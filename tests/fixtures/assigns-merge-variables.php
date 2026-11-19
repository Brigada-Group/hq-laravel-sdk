<?php

// A defaults file that assigns the variables Laravel's mergeConfigFrom($path, $key) uses in its own scope. $key must be null (not
// a random string): with $key = 'x', mergeConfigFrom would still write ['a' => 1] under "hq" and a revert would go unnoticed.
$key = null;
$path = 'x';
$config = [];

return ['a' => 1];
