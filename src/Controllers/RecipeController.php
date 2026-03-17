<?php
namespace App\Controllers;

use App\Database;
use PDO;

class RecipeController {
    
    /**
     * Fetch all recipes from the vault
     * Maps to the 'recipes' table in pantry_ce_show_live
     */
    public function getAllRecipes() {
        $db = Database::connect();
        
        $sql = "SELECT id, title, description, prep_time, cook_time, image_url, average_rating, rating_count, nutrition_info, ingredients, instructions, notes, yields, is_draft
                FROM recipes 
                ORDER BY id DESC";
                
        $stmt = $db->query($sql);
        $recipes = $stmt->fetchAll();

        $formatted = array_map(function($r) {
            return [
                'id' => $r['id'],
                'title' => $r['title'],
                'description' => $r['description'],
                'prepTime' => $r['prep_time'],
                'cookTime' => $r['cook_time'],
                'imageUrl' => $r['image_url'],
                'rating' => $r['average_rating'],
                'ratingCount' => $r['rating_count'],
                'nutritionInfo' => $r['nutrition_info'],
                'ingredients'  => $r['ingredients'],
                'instructions' => $r['instructions'],
                'notes' => $r['notes'],
                'yields' => $r['yields'],
                'isOilFree' => str_contains(strtolower($r['description']), 'oil-free'),
                'isDraft' => (bool)$r['is_draft']
            ];
        }, $recipes);

        echo json_encode($formatted);
    }

    
    public function getSingleRecipe() {
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid or Missing ID"]);
            return;
        }

        $id = (int)$_GET['id'];
        $db = database::connect();

        // Prepare and execute the targeted query
        $sql = "SELECT * FROM recipes WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);

        // Handle the 404 if vault entry doesn't exist
        if(!$recipe) {
            http_response_code(404);
            echo json_encode(['message' => "Recipe Not Found."]);
            return;
        }

        // Decode the heavy JSON strings into PHP arrays for the Front-End
        $recipe['ingredients'] = json_decode($recipe['ingredients'], true) ?: [];
        $recipe['instructions'] = json_decode($recipe['instructions'], true) ?: [];
        $recipe['nutrition_info'] = json_decode($recipe['nutrition_info'], true) ?: [];
        $recipe['isOilFree'] = str_contains(strtolower($recipe['description']), 'oil-free');

        echo json_encode($recipe);
    }

    public function addRecipe() {
        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);

        $sql = "INSERT INTO recipes (title, description, ingredients, instructions, prep_time, cook_time, nutrition_info) 
                VALUES (:title, :description, :ingredients, :instructions, :prep_time, :cook_time, :nutrition_info)";
        
        $stmt = $db->prepare($sql);
        $success = $stmt->execute([
            ':title' => $data['title'],
            ':description' => $data['description'],
            ':ingredients' => json_encode($data['ingredients']),
            ':instructions' => json_encode($data['instructions']),
            ':prep_time' => $data['prepTime'],
            ':cook_time' => $data['cookTime'],
            ':nutrition_info' => json_encode($data['nutritionInfo'])
        ]);

        echo json_encode(["success" => $success, "message" => "Recipe Vaulted!"]);
    }

    /**
     * Delete a recipe from the vault forever. No going back!
     */
    public function deleteRecipe() {
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["message" => "Ivalid or Missing ID"]);
            return;
        }

        $id = (int)$_GET['id'];
        $db = Database::connect();

        $sql = "DELETE FROM recipes WHERE id = :id";
        $stmt = $db->prepare($sql);
        $success = $stmt->execute([':id' => $id]);

        if ($success) {
            echo json_encode(["success" => true, "message" => "Recipe Deleted Successfully."]);
        }
        else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to Delete the Recipe."]);
        }
    }

    /**
     * Toggle the draft status so a recipe can be hidden from the public grid.
     */
    public function toggleDraft() {
        $data = json_decode(file_get_contents("php://input"), true);

        if (!isset($data['id']) || !isset($data['is_draft'])) {
            http_response_code(400);
            echo json_encode(["message" => "Missing ID or Draft Status."]);
            return;
        }
        $db = Database::connect();

        $sql = "UPDATE recipes SET is_draft = :is_draft WHERE id = :id";
        $stmt = $db->prepare($sql);
        $success = $stmt->execute([
            ':is_draft' => (int)$data['is_draft'],
            ':id' => (int)$data['id']
        ]);

        if ($success) {
            echo json_encode(["success" => true, "message" => "Draft status updated."]);
        }
        else {
            echo json_encode(["success" => false, "message" => "Failed to update Draft Status."]);
        }
    }

    /**
     * Handle multiple image uploads straight to the public/images folder
     */
    public function uploadImages() {
        // Ensure the images directory actually exists inside public/
        $uploadDir = __DIR__ . '/../../public/images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $uploadedPaths = [];
        $errors = [];

        // Check if our 'images' array came through from React
        if (isset($_FILES['images'])) {
            $files = $_FILES['images'];
            $count = count($files['name']);

            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $tmpName = $files['tmp_name'][$i];
                    
                    // Clean the filename so rogue spaces don't break our URLs
                    $name = basename($files['name'][$i]);
                    $name = preg_replace("/[^a-zA-Z0-9.-]/", "_", $name);
                    
                    // Slap a timestamp on the front so you don't accidentally overwrite 'ham.jpg'
                    $newName = time() . '_' . $name;
                    $destination = $uploadDir . $newName;

                    if (move_uploaded_file($tmpName, $destination)) {
                        // Store the relative path to send back to React
                        $uploadedPaths[] = '/images/' . $newName;
                    } else {
                        $errors[] = "Failed to move: $name";
                    }
                }
            }
        }

        if (empty($uploadedPaths) && !empty($errors)) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Upload failed.", "errors" => $errors]);
        } else {
            echo json_encode(["success" => true, "urls" => $uploadedPaths, "errors" => $errors]);
        }
    }

    /**
     * Update an existing recipe in the vault
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

        // A massive UPDATE statement to overwrite the old data
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
                image_url = :image_url
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
            ':id' => $id
        ]);

        if ($success) {
            echo json_encode(["success" => true, "message" => "Recipe successfully updated!"]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to update the recipe."]);
        }
    }
    /**
     * Save a recipe generated by Emma's Premium Engine.
     */
public function saveGeneratedRecipe() {
        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);

        // Accept the properly parsed arrays from React
        $title = $data['title'] ?? 'Emma\'s AI Creation';
        $description = $data['description'] ?? 'A glorious AI-generated WFPB meal.';
        $ingredients = $data['ingredients'] ?? [];
        $instructions = $data['instructions'] ?? [];
        $prepTime = $data['prepTime'] ?? 0;
        $cookTime = $data['cookTime'] ?? 0;
        $yields = $data['yields'] ?? null;
        $notes = $data['notes'] ?? 'AI Draft - Awaiting Admin formatting.';
        
        // This is the massive Base64 string from React
        $rawImageUrl = $data['imageUrl'] ?? ''; 
        $finalImageUrl = '';

        if (strpos($rawImageUrl, 'data:image') === 0) {
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

            $filepath = $uploadDir . $filename;
            
            // Write the physical file to the server folder
            file_put_contents($filepath, $decodedData);

            $finalImageUrl = '/images/' . $filename;
        } else {
            $finalImageUrl = $rawImageUrl ?: '/images/default-veggie-vault-placeholder.jpg';
        }

        $sql = "INSERT INTO recipes (title, description, ingredients, instructions, prep_time, cook_time, nutrition_info, image_url, notes, is_draft, yields) 
                VALUES (:title, :description, :ingredients, :instructions, :prep_time, :cook_time, :nutrition_info, :image_url, :notes, :is_draft, :yields)";
        
        $stmt = $db->prepare($sql);
        $success = $stmt->execute([
            ':title' => $title,
            ':description' => $description, 
            ':ingredients' => json_encode($ingredients), 
            ':instructions' => json_encode($instructions), 
            ':prep_time' => $prepTime,
            ':cook_time' => $cookTime,
            ':nutrition_info' => json_encode([]), 
            ':image_url' => $finalImageUrl, // Save the clean path to the DB
            ':notes' => $notes, 
            ':is_draft' => 1,
            ':yields' => $yields
        ]);

        if ($success) {
            echo json_encode(["success" => true, "message" => "Smashing! Recipe vaulted as a draft."]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Oh dear. Failed to vault the generated recipe."]);
        }
    }
}