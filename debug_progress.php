<?php
require_once 'api/db.php';
header('Content-Type: text/plain');

echo "--- User Data ---\n";
$stmt = $pdo->query("SELECT id, username, name FROM users");
while($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | User: {$row['username']} | Name: {$row['name']}\n";
}

echo "\n--- Progress Data ---\n";
$stmt = $pdo->query("SELECT * FROM student_reading_progress");
while($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | StudentID: {$row['student_id']} | DocID: {$row['document_id']} | Progress: {$row['progress_percentage']}% | Pos: {$row['last_position']}\n";
}

echo "\n--- Document Data ---\n";
$stmt = $pdo->query("SELECT id, title FROM documents");
while($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | Title: {$row['title']}\n";
}
