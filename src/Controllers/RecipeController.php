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
        $db = Database::connect();
        
        // Added is_public and image_source to the SELECT
        $sql = "SELECT id, title, description, image_url, yields, prep_time_mins, cook_time_mins, is_wfpb, is_oil_free, is_public, image_source, created_at 
        FROM recipes 
        ORDER BY id DESC";
                
        $stmt = $db->query($sql);
        $recipes = $stmt->fetchAll();

        $formatted = array_map(function($r) {
            return [
                'id' => $r['id'],
                'title' => $r['title'],
                'description' => $r['description'],
                'prepTime' => $r['prep_time_mins'],
                'cookTime' => $r['cook_time_mins'],
                'imageUrl' => $r['image_url'],
                'yields' => $r['yields'],
                'isWfpb' => (bool)$r['is_wfpb'], 
                'isOilFree' => (bool)$r['is_oil_free'],
                'isDraft' => !(bool)$r['is_public'], 
                'isPublic' => (bool)$r['is_public'],
                'imageSource' => $r['image_source'],
                'createdAt' => $r['created_at'] 
            ];
        }, $recipes);

        echo json_encode($formatted);
    }    /**
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
        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);

        // 1. Unpack the data from React
        $title = $data['title'] ?? 'Emma\'s AI Creation';
        $description = $data['description'] ?? 'A glorious AI-generated WFPB meal.';
        $ingredients = $data['ingredients'] ?? [];
        $instructions = $data['instructions'] ?? [];
        $prepTime = $data['prepTime'] ?? 0;
        $cookTime = $data['cookTime'] ?? 0;
        $yields = $data['yields'] ?? null;
        $notes = $data['notes'] ?? '';

        $isPublic = 0; 
        $imageSource = 'none';
        
        $rawImageUrl = $data['imageUrl'] ?? ''; 
        $finalImageUrl = '';

        // 2. Handle the Image
        if (!empty($rawImageUrl) && strpos($rawImageUrl, 'data:image') === 0) {
            list($type, $imageData) = explode(';', $rawImageUrl);
            list(, $imageData)      = explode(',', $imageData);
            $decodedData = base64_decode($imageData);
            
            $ext = 'jpg';
            if (str_contains($type, 'png')) $ext = 'png';
            if (str_contains($type, 'webp')) $ext = 'webp';

            $filename = time() . '_ai_recipe_img.' . $ext;
            $uploadDir = __DIR__ . '/../../public/images/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            file_put_contents($uploadDir . $filename, $decodedData);
            $finalImageUrl = '/images/' . $filename;
            
            $imageSource = 'ai'; 
            $isPublic = 1; // Unlock it for the General Public Feed!
        } else {
            $finalImageUrl = $rawImageUrl ?: '/images/default-veggie-vault-placeholder.jpg';
        }

        // 3. The SQL Transaction (All or Nothing!)
        // 3. The SQL Transaction (All or Nothing!)
        try {
            $db->beginTransaction();

            // STEP A: Create the Parent Recipe (Now with the notes column!)
            $sqlRecipe = "INSERT INTO recipes (title, description, notes, image_url, yields, prep_time_mins, cook_time_mins, is_wfpb, is_oil_free, is_public, image_source) 
                          VALUES (:title, :description, :notes, :image_url, :yields, :prep_time_mins, :cook_time_mins, 1, 1, :is_public, :image_source)";
            
            $stmtRecipe = $db->prepare($sqlRecipe);
            $stmtRecipe->execute([
                ':title' => $title,
                ':description' => $description, 
                ':notes' => $notes, 
                ':image_url' => $finalImageUrl,
                ':yields' => $yields,
                ':prep_time_mins' => $prepTime,
                ':cook_time_mins' => $cookTime,
                ':is_public' => $isPublic,
                ':image_source' => $imageSource
            ]);

            // Grab the ID of the recipe we just made
            $recipeId = $db->lastInsertId();

            // STEP B: Insert Instructions
            if (!empty($instructions)) {
                $sqlInst = "INSERT INTO instructions (recipe_id, step_number, instruction_text) VALUES (:recipe_id, :step_number, :instruction_text)";
                $stmtInst = $db->prepare($sqlInst);
                foreach ($instructions as $index => $stepText) {
                    $stmtInst->execute([
                        ':recipe_id' => $recipeId,
                        ':step_number' => $index + 1,
                        ':instruction_text' => $stepText
                    ]);
                }
            }

            // STEP C: Insert Ingredients
            if (!empty($ingredients)) {
                $sqlIng = "INSERT INTO ingredients (recipe_id, quantity, unit, ingredient_name) VALUES (:recipe_id, :quantity, :unit, :ingredient_name)";
                $stmtIng = $db->prepare($sqlIng);
                foreach ($ingredients as $ingText) {
                    // Check if React sent an object or a plain string
                    $ingName = is_array($ingText) ? ($ingText['ingredient_name'] ?? json_encode($ingText)) : $ingText;
                    
                    $stmtIng->execute([
                        ':recipe_id' => $recipeId,
                        ':quantity' => 0.00, 
                        ':unit' => '',
                        ':ingredient_name' => $ingName 
                    ]);
                }
            }

            // STEP D: Insert Macros
            $calories = $data['calories'] ?? 0;
            $protein_g = $data['protein_g'] ?? 0;
            $carbs_g = $data['carbs_g'] ?? 0;
            $fat_g = $data['fat_g'] ?? 0;
            $fiber_g = $data['fiber_g'] ?? 0;

            // Only insert if we actually captured some nutritional data
            if ($calories > 0 || $protein_g > 0) {
                $sqlMacros = "INSERT INTO macros (recipe_id, calories, protein_g, carbs_g, fat_g, fiber_g, math_calculations)
                        VALUES (:recipe_id, :calories, :protein_g, :carbs_g, :fat_g, :fiber_g, :math_calculations)";
                $stmtMacros = $db->prepare($sqlMacros);

                $stmtMacros->execute([
                    ':recipe_id' => $recipeId,
                    ':calories' => (float)$calories,
                    ':protein_g' => (float)$protein_g,
                    ':carbs_g' => (float)$carbs_g,
                    ':fat_g' => (float)$fat_g,
                    ':fiber_g' => (float)$fiber_g,
                    ':math_calculations' => ''
                ]);
            }

            // STEP E: Insert Micros (If they clicked Deep Dive before saving)
            $micros = $data['micros'] ?? [];
            if (!empty($micros) && is_array($micros)) {
                $sqlMicros = "INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations)
                              VALUES (:recipe_id, :nutrient_name, :amount, :unit, :daily_value_percentage, :math_calculations)";
                $stmtMicros = $db->prepare($sqlMicros);
                foreach ($micros as $micro) {
                    $stmtMicros->execute([
                        ':recipe_id' => $recipeId,
                        ':nutrient_name' => $micro['name'] ?? $micro['nutrient_name'] ?? 'Unknown',
                        ':amount' => (float)($micro['amount'] ?? 0),
                        ':unit' => $micro['unit'] ?? 'mg',
                        ':daily_value_percentage' => (float)($micro['dv'] ?? $micro['daily_value_percentage'] ?? 0),
                        ':math_calculations' => ''
                    ]);
                }
            }

            // Everything worked! Commit to the database.
            $db->commit();

            $msg = $isPublic ? "Brilliant! Full recipe vaulted and published to the feed." : "Smashing! Text draft locked in your private vault.";
            echo json_encode(["success" => true, "message" => $msg]);

        } catch (\Exception $e) {
            // Something broke. Cancel the entire transaction.
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
    }
    /*
     * Update an existing recipe.
     * "Wipe and Replace" strategy for relational data.
     */
    public function updateRecipe() {
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid or Missing ID"]);
            return;
        }

        $id = (int)$_GET['id'];
        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);

        // A massive UPDATE statement to overwrite the old data AND our new columns
        $sql = "UPDATE recipes SET 
                title = :title, 
                description = :description, 
                ingredients = :ingredients, 
                instructions = :instructions, 
                prep_time = :prep_time, 
                cook_time = :cook_time, 
                nutrition_info = :nutrition_info, 
                notes = :notes,
                yields = :yields,
                image_url = :image_url,
                is_public = :is_public,
                image_source = :image_source
                WHERE id = :id";
        
        $stmt = $db->prepare($sql);
        $success = $stmt->execute([
            ':title' => $data['title'] ?? '',
            ':description' => $data['description'] ?? '',
            ':ingredients' => json_encode($data['ingredients'] ?? []),
            ':instructions' => json_encode($data['instructions'] ?? []),
            ':prep_time' => $data['prepTime'] ?? 0,
            ':cook_time' => $data['cookTime'] ?? 0,
            ':nutrition_info' => json_encode($data['nutritionInfo'] ?? []),
            ':notes' => $data['notes'] ?? '',
            ':yields' => $data['yields'] ?? '',
            ':image_url' => $data['imageUrl'] ?? '',
            ':is_public' => $data['isPublic'] ?? 0,
            ':image_source' => $data['imageSource'] ?? 'none',
            ':id' => $id
        ]);

        if ($success) {
            echo json_encode(["success" => true, "message" => "Recipe successfully updated!"]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to update the recipe."]);
        }
    }
    public function deleteRecipe() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID.']);
            return;
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM recipes WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Recipe Deleted.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete recipe: ' . $e->getMessage()]);
        }
    }
}