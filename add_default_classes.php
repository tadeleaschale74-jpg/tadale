<?php
require "admin/db.php";

$classes = ['9', '10', '11', '12', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

foreach ($classes as $class) {
    $stmt = $conn->prepare("INSERT IGNORE INTO classes(class_name) VALUES(?)");
    $stmt->bind_param("s", $class);
    $stmt->execute();
}

$subjects = [
    'Mathematics',
    'English',
    'Physics', // Natural Science
    'Chemistry', // Natural Science
    'Biology', // Natural Science
    'History', // Social Science
    'Geography', // Social Science
    'Sociology', // Social Science
    'Psychology', // Social Science
    'Art',
    'Music',
    'Physical Education'
];

// Get classes
$classes_result = $conn->query("SELECT id FROM classes");

while ($class = $classes_result->fetch_assoc()) {
    foreach ($subjects as $subject) {
        $stmt = $conn->prepare("INSERT IGNORE INTO subjects(name, class_id) VALUES(?, ?)");
        $stmt->bind_param("si", $subject, $class['id']);
        $stmt->execute();
    }
}

// Add roll column if not exists
$conn->query("ALTER TABLE students ADD COLUMN roll VARCHAR(10) DEFAULT NULL");

echo "Classes 9-12 and A-J and 12 subjects per class added. Roll column added to students.";
?>