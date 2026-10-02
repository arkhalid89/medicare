<?php
/**
 * Opening the project folder itself (e.g. http://localhost/medicare-practice/)
 * forwards to the public folder, where the application lives.
 */
//This is the new change
header('Location: public/');
exit;
