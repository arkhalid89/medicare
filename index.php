<?php
/**
 * Opening the project folder itself (e.g. http://localhost/medicare-practice/)
 * forwards to the public folder, where the application lives.
 */
header('Location: public/');
exit;
