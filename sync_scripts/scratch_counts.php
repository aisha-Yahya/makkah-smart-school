<?php
$db = new PDO('sqlite:api/data/school_pro_synced.sqlite');
foreach (["students", "student_monthly_grades", "student_monthly_summaries", "student_certificate_grades", "student_account_summary"] as $t) {
    echo $t . ": " . $db->query("SELECT count(*) FROM " . $t)->fetchColumn() . PHP_EOL;
}
