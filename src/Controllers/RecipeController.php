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
        
        $sql = "SELECT id, title, description, prep_time, cook_time, image_url, average_rating, rating_count, nutrition_info, ingredients, instructions, notes, yields
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
                'isOilFree' => str_contains(strtolower($r['description']), 'oil-free')
            ];
        }, $recipes);

        echo json_encode($formatted);
    }

    /**
     * Add a new entry
     */
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
}