<?php

namespace App\Controllers;

use PDO;
use Exception;
use App\Database;

class RecipeController {
    private $db;

    public function __construct() {
        $database = new Database();
        
        // Note: 'getConnection()' is the standard name. If your Database.php uses 
        // something like 'connect()' instead, just swap the word below!
        $this->db = $database->connect(); 
    }

    /**
     * Fetches the high-level list of all recipes for the Home Grid.
     * Includes core macros for the quick-view badges!
     */
    public function getAllRecipes() {
        try {
            // We join the macros table so the Home page cards can show 
            // the Protein/Calorie badges without needing a second API call.
            $query = "
                SELECT 
                    r.id, r.title, r.description, r.image_url, r.yields, 
                    r.prep_time_mins, r.cook_time_mins, r.is_wfpb, r.is_oil_free,
                    m.calories, m.protein_g, m.carbs_g, m.fat_g
                FROM recipes r
                LEFT JOIN macros m ON r.id = m.recipe_id
                ORDER BY r.created_at DESC
            ";

            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode($recipes);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'The vault is stuck: ' . $e->getMessage()]);
        }
    }
    /**
     * Fetch a single recipe with all its glorious relational data.
     * Perfect for populating RecipeDetails.jsx
     */
    public function getRecipeById() {
        // 1. Grab the ID directly from the URL query string
        $id = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID. I cannot fetch a ghost.']);
            return;
        }

        try {
            // 2. Fetch the overarching recipe details
            $stmt = $this->db->prepare("SELECT * FROM recipes WHERE id = ?");
            $stmt->execute([$id]);
            $recipe = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$recipe) {
                http_response_code(404);
                echo json_encode(['error' => 'Recipe not found. Check the vault again.']);
                return;
            }

            // 3. Fetch the ingredients
            $stmt = $this->db->prepare("SELECT * FROM ingredients WHERE recipe_id = ?");
            $stmt->execute([$id]);
            $recipe['ingredients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Fetch the instructions (ordered properly!)
            $stmt = $this->db->prepare("SELECT * FROM instructions WHERE recipe_id = ? ORDER BY step_number ASC");
            $stmt->execute([$id]);
            $recipe['instructions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Fetch the macros and the all-important math calculations
            $stmt = $this->db->prepare("SELECT * FROM macros WHERE recipe_id = ?");
            $stmt->execute([$id]);
            $recipe['macros'] = $stmt->fetch(PDO::FETCH_ASSOC);

            // 6. Fetch the comprehensive 15 micros
            $stmt = $this->db->prepare("SELECT * FROM micros WHERE recipe_id = ?");
            $stmt->execute([$id]);
            $recipe['micros'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 7. Echo the pristine, perfectly structured JSON back to React!
            echo json_encode($recipe);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch recipe: ' . $e->getMessage()]);
        }
    }
    /**
     * The master function to insert a brand new recipe.
     * Utilizes transactions to ensure everything saves perfectly, or not at all.
     */
    public function createRecipe($data) {
        try {
            // INITIATE LOCKDOWN on the database - start the transaction
            $this->db->beginTransaction();

            // Insert Core Recipe Data
            $stmt = $this->db->prepare("
                INSERT INTO recipes (title, description, image_url, yields, prep_time_mins, cook_time_mins, is_wfpb, is_oil_free, oil_rationale) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['title'],
                $data['description'],
                $data['image_url'] ?? null,
                $data['yields'],
                $data['prep_time_mins'],
                $data['cook_time_mins'],
                $data['is_wfpb'] ?? 1, 
                $data['is_oil_free'] ?? 1,
                $data['oil_rationale'] ?? null 
            ]);

            // Grab the newly created ID to link the rest of the tables
            $recipeId = $this->db->lastInsertId();

            // Insert Ingredients
            if (!empty($data['ingredients'])) {
                $stmt = $this->db->prepare("
                    INSERT INTO ingredients (recipe_id, quantity, unit, ingredient_name, notes) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($data['ingredients'] as $ing) {
                    $stmt->execute([$recipeId, $ing['quantity'], $ing['unit'], $ing['ingredient_name'], $ing['notes'] ?? null]);
                }
            }

            // Insert Instructions
            if (!empty($data['instructions'])) {
                $stmt = $this->db->prepare("
                    INSERT INTO instructions (recipe_id, step_number, instruction_text) 
                    VALUES (?, ?, ?)
                ");
                foreach ($data['instructions'] as $index => $inst) {
                    // Using index + 1 to automatically generate the step number sequentially
                    $stmt->execute([$recipeId, $index + 1, $inst['instruction_text']]);
                }
            }

            // Insert Macros (Ensuring we capture the math!)
            if (!empty($data['macros'])) {
                $stmt = $this->db->prepare("
                    INSERT INTO macros (recipe_id, calories, protein_g, carbs_g, fat_g, math_calculations) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $m = $data['macros'];
                $stmt->execute([$recipeId, $m['calories'], $m['protein_g'], $m['carbs_g'], $m['fat_g'], $m['math_calculations']]);
            }

            // Insert Micros (Looping through the comprehensive list)
            if (!empty($data['micros'])) {
                $stmt = $this->db->prepare("
                    INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($data['micros'] as $micro) {
                    $stmt->execute([
                        $recipeId, 
                        $micro['nutrient_name'], 
                        $micro['amount'], 
                        $micro['unit'], 
                        $micro['daily_value_percentage'], 
                        $micro['math_calculations']
                    ]);
                }
            }

            // If we made it this far, COMMIT the transaction!
            $this->db->commit();

            return ['success' => true, 'message' => 'Recipe saved flawlessly.', 'recipe_id' => $recipeId];

        } catch (Exception $e) {
            // If ANYTHING fails, roll back the entire database to how it was before we started
            $this->db->rollBack();
            return ['success' => false, 'error' => 'Database transaction failed: ' . $e->getMessage()];
        }
    }

/**
     * Saves a beautifully generated recipe straight from Emma's Engine.
     * Base64 image interceptor to reduce the file size
     */
public function saveGeneratedRecipe() {
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'No recipe data received.']);
        return;
    }

    try {
        $this->db->beginTransaction();

        // --- EMMA'S IMAGE INTERCEPTOR ---
        $imageUrl = $data['imageUrl'] ?? $data['image_url'] ?? null;
        
        // If the image is a Base64 string, let's save it as a file instead of bloating the DB
        if (strpos($imageUrl, 'data:image') === 0) {
            $format = strpos($imageUrl, 'data:image/png') === 0 ? 'png' : 'jpg';
            $image_parts = explode(";base64,", $imageUrl);
            $image_base64 = base64_decode($image_parts[1]);
            
            // Create a unique filename based on the title
            $safeTitle = preg_replace('/[^a-z0-9]+/', '-', strtolower($data['title']));
            $fileName = $safeTitle . '-' . time() . '.' . $format;
            
            $uploadDir = __DIR__ . '/../../public/uploads/recipes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            file_put_contents($uploadDir . $fileName, $image_base64);
            
            // This is what actually gets saved to the DB
            $imageUrl = '/uploads/recipes/' . $fileName;
        }

        // INSERT CORE RECIPE
        $stmt = $this->db->prepare("
            INSERT INTO recipes (title, description, image_url, yields, prep_time_mins, cook_time_mins, is_wfpb, is_oil_free, oil_rationale) 
            VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)
        ");
        $stmt->execute([
            $data['title'],
            $data['description'] ?? '',
            $imageUrl,
            $data['yields'] ?? 'Unknown',
            intval(preg_replace('/[^0-9]/', '', $data['prepTime'] ?? $data['prep_time_mins'] ?? 0)),
            intval(preg_replace('/[^0-9]/', '', $data['cookTime'] ?? $data['cook_time_mins'] ?? 0)),
            $data['notes'] ?? 'Strictly oil-free WFPB.'
        ]);
        
        $recipeId = $this->db->lastInsertId();

            // INGREDIENTS
            $ingList = $data['ingredients'] ?? [];
            if (!empty($ingList)) {
                $stmt = $this->db->prepare("INSERT INTO ingredients (recipe_id, quantity, unit, ingredient_name) VALUES (?, ?, ?, ?)");
                foreach ($ingList as $ing) {
                    if (strpos($ing, '##') === 0 || empty(trim($ing))) continue;
                    $cleanIng = ltrim(trim($ing), '- *'); 
                    $stmt->execute([$recipeId, 1, 'serving', $cleanIng]);
                }
            }

            // INSTRUCTIONS
            $instList = $data['instructions'] ?? [];
            if (!empty($instList)) {
                $stmt = $this->db->prepare("INSERT INTO instructions (recipe_id, step_number, instruction_text) VALUES (?, ?, ?)");
                foreach ($instList as $index => $inst) {
                    $stmt->execute([$recipeId, $index + 1, $inst]);
                }
            }

            // NUTRITION (The Ultra-Robust Parser)
            $nutrition = $data['nutritionInformation'] ?? $data['nutrition_info'] ?? $data['nutrition'] ?? null;
            
            if ($nutrition) {
                $getNum = function($val) {
                    return floatval(preg_replace('/[^0-9.]/', '', $val));
                };

                // Insert Macros
                $stmt = $this->db->prepare("INSERT INTO macros (recipe_id, calories, protein_g, carbs_g, fat_g, math_calculations) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $recipeId,
                    $getNum($nutrition['Calories'] ?? 0),
                    $getNum($nutrition['Protein'] ?? 0),
                    $getNum($nutrition['Carbohydrates'] ?? $nutrition['Carbs'] ?? 0),
                    $getNum($nutrition['Fat'] ?? 0),
                    "Core macros verified."
                ]);

                // Insert Micros
                $ignoreKeys = [
                    'Calories', 'Protein', 'Carbohydrates', 'Carbs', 'Fat', 
                    'Calculations', 'Total Recipe Sums', 'Per Serving', 'Notes'
                ];
                
                $stmt = $this->db->prepare("INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations) VALUES (?, ?, ?, ?, ?, ?)");
                
                foreach ($nutrition as $key => $val) {
                    // Skip if it's a macro or a giant text block
                    if (in_array($key, $ignoreKeys) || is_array($val) || strlen($val) > 100) continue;

                    // Extract the Daily Value %
                    preg_match('/(\d+)/', $val, $matches);
                    $dv = isset($matches[1]) ? intval($matches[1]) : 0;
                    
                    // Save only the clean nutrient data
                    $stmt->execute([$recipeId, $key, $val, 'amt', $dv, "Emma Engine Analysis"]);
                }
            }
            $this->db->commit();
        echo json_encode(['success' => true, 'recipe_id' => $recipeId, 'image_path' => $imageUrl]);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'The Vault rejected the deposit: ' . $e->getMessage()]);
        }
    }
    /**
     * Update an existing recipe. 
     * "Wipe and Replace" strategy for relational data.
     */
    public function updateRecipe() {
        // Grab the JSON payload from the frontend
        $data = json_decode(file_get_contents("php://input"), true);
        $recipeId = $data['id'] ?? null;

        if (!$recipeId) {
            http_response_code(400);
            echo json_encode(['error' => 'Recipe ID is required for an update, darling.']);
            return;
        }

        try {
            $this->db->beginTransaction();

            // Update Core Recipe
            $stmt = $this->db->prepare("
                UPDATE recipes 
                SET title = ?, description = ?, image_url = ?, yields = ?, prep_time_mins = ?, cook_time_mins = ?, 
                    is_wfpb = ?, is_oil_free = ?, oil_rationale = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['title'], 
                $data['description'], 
                $data['image_url'] ?? null,
                $data['yields'], 
                $data['prep_time_mins'], 
                $data['cook_time_mins'], 
                $data['is_wfpb'] ?? 1, 
                $data['is_oil_free'] ?? 1, 
                $data['oil_rationale'] ?? null,
                $recipeId
            ]);

            // Wipe the old relational data clean
            $this->db->prepare("DELETE FROM ingredients WHERE recipe_id = ?")->execute([$recipeId]);
            $this->db->prepare("DELETE FROM instructions WHERE recipe_id = ?")->execute([$recipeId]);
            $this->db->prepare("DELETE FROM macros WHERE recipe_id = ?")->execute([$recipeId]);
            $this->db->prepare("DELETE FROM micros WHERE recipe_id = ?")->execute([$recipeId]);

            // Insert the fresh ingredients
            if (!empty($data['ingredients'])) {
                $stmt = $this->db->prepare("INSERT INTO ingredients (recipe_id, quantity, unit, ingredient_name, notes) VALUES (?, ?, ?, ?, ?)");
                foreach ($data['ingredients'] as $ing) {
                    $stmt->execute([$recipeId, $ing['quantity'], $ing['unit'], $ing['ingredient_name'], $ing['notes'] ?? null]);
                }
            }

            // Insert the instructions
            if (!empty($data['instructions'])) {
                $stmt = $this->db->prepare("INSERT INTO instructions (recipe_id, step_number, instruction_text) VALUES (?, ?, ?)");
                foreach ($data['instructions'] as $index => $inst) {
                    $stmt->execute([$recipeId, $index + 1, $inst['instruction_text']]);
                }
            }

            //  Insert the macros
            if (!empty($data['macros'])) {
                $stmt = $this->db->prepare("INSERT INTO macros (recipe_id, calories, protein_g, carbs_g, fat_g, math_calculations) VALUES (?, ?, ?, ?, ?, ?)");
                $m = $data['macros'];
                $stmt->execute([$recipeId, $m['calories'], $m['protein_g'], $m['carbs_g'], $m['fat_g'], $m['math_calculations']]);
            }

            // Insert the 15 micros
            if (!empty($data['micros'])) {
                $stmt = $this->db->prepare("INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($data['micros'] as $micro) {
                    $stmt->execute([$recipeId, $micro['nutrient_name'], $micro['amount'], $micro['unit'], $micro['daily_value_percentage'], $micro['math_calculations']]);
                }
            }

            $this->db->commit();
            echo json_encode(['success' => true, 'message' => 'Recipe updated brilliantly.']);

        } catch (Exception $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Update failed: ' . $e->getMessage()]);
        }
    }

    public function deleteRecipe() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID. I cannot delete a ghost.']);
            return;
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM recipes WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Recipe completely obliterated.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete recipe: ' . $e->getMessage()]);
        }
    }
}