<?php
require "admin/db.php";

$subjects = [
    'Mathematics',
    'English',
    'Physics',
    'Chemistry',
    'Biology',
    'History',
    'Geography',
    'Art',
    'Music',
    'Physical Education',
    'Computer Science',
    'Foreign Language'
];

// Get classes
$classes = $conn->query("SELECT id FROM classes");

while ($class = $classes->fetch_assoc()) {
    foreach ($subjects as $subject) {
        $stmt = $conn->prepare("INSERT IGNORE INTO subjects(name, class_id) VALUES(?, ?)");
        $stmt->bind_param("si", $subject, $class['id']);
        $stmt->execute();
    }
}

echo "Subjects added to all classes.";
?>