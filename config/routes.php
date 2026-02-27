<?php
use App\Router;

$router = new Router();

$router->add('GET', '/recipes', 'RecipeController', 'getAllRecipes');
$router->add('POST', '/recipes', 'RecipeController', 'addRecipe');
$router->add('GET', '/recipes/single', 'RecipeController', 'getSingleRecipe');

return $router;