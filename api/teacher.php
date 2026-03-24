<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once __DIR__ . '/extract_text.php';
require_once __DIR__ . '/generate_questions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// User lookup for permissions
$username = $_POST['username'] ?? $_GET['username'] ?? '';
$teacher_id = 0;
if ($username) {
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && $u['role'] === 'teacher') {
        $teacher_id = $u['id'];
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized or missing user']);
        exit;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing username']);
    exit;
}

if ($action === 'get_subjects') {
    $category_id = $_GET['category_id'] ?? 0;
    $typeMap = [
        1 => 'General-Education',
        2 => 'Major',
        3 => 'Professional-Education'
    ];
    $categoryType = $typeMap[$category_id] ?? '';

    if ($categoryType) {
        $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE type = ? ORDER BY name ASC");
        $stmt->execute([$categoryType]);
        echo json_encode(['status' => 'success', 'subjects' => $stmt->fetchAll()]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid category']);
    }
    exit;
}

if ($action === 'upload_doc') {
    $subject_id = $_POST['subject_id'] ?? 0;
    
    // Check for NEW subject
    $new_subject_name = $_POST['new_subject_name'] ?? '';
    if ($subject_id == 0 && !empty($new_subject_name)) {
        $category_id = $_POST['category_id'] ?? 1;
        $typeMap = [1 => 'General-Education', 2 => 'Major', 3 => 'Professional-Education'];
        $catType = $typeMap[$category_id] ?? 'General-Education';
        
        // Insert if not exists
        $stmt = $pdo->prepare("INSERT INTO subjects (name, type) VALUES (?, ?)");
        $stmt->execute([$new_subject_name, $catType]);
        $subject_id = $pdo->lastInsertId();
    }
    
    $title = $_POST['title'] ?? '';

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'No file uploaded or upload error.']);
        exit;
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['pdf', 'docx', 'ppt', 'pptx'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file type.']);
        exit;
    }

    // Determine target folder based on subject_id
    $typeMap = [
        1 => 'General-Education',
        2 => 'Major',
        3 => 'Professional-Education'
    ];
    $folder = $typeMap[$subject_id] ?? 'General-Education';
    $targetDir = "../$folder/";
    
    // Ensure dir exists
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    
    // Sanitize filename
    $fn = preg_replace("/[^a-zA-Z0-9.-]/", "_", basename($file['name']));
    $targetPath = $targetDir . time() . '_' . $fn;
    $dbPath = "$folder/" . time() . '_' . $fn;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $description = $_POST['description'] ?? '';
        // DB Insert
        $stmt = $pdo->prepare("INSERT INTO documents (title, description, type, file_path, subject_id, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $description, $ext, $dbPath, $subject_id, $teacher_id]);
        
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file.']);
    }
    exit;
}

if ($action === 'get_documents') {
    $subject_id = $_GET['subject_id'] ?? 0;
    $category_id = $_GET['category_id'] ?? 0;

    if ($subject_id > 0) {
        $stmt = $pdo->prepare("SELECT id, title, description, type, file_path FROM documents WHERE subject_id = ?");
        $stmt->execute([$subject_id]);
    } else {
        $typeMap = [1 => 'General-Education', 2 => 'Major', 3 => 'Professional-Education'];
        $categoryType = $typeMap[$category_id] ?? '';
        $stmt = $pdo->prepare("SELECT d.id, d.title, d.description, d.type, d.file_path FROM documents d JOIN subjects s ON d.subject_id = s.id WHERE s.type = ?");
        $stmt->execute([$categoryType]);
    }
    $docs = $stmt->fetchAll();
    echo json_encode(['status' => 'success', 'documents' => $docs]);
    exit;
}

if ($action === 'delete_doc') {
    $document_id = $_POST['document_id'] ?? 0;
    
    // Get file path to delete from disk
    $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $doc = $stmt->fetch();
    
    if ($doc) {
        $filePath = "../" . $doc['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Delete from DB
        $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->execute([$document_id]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Document not found.']);
    }
    exit;
}

if ($action === 'auto_generate_exam') {
    $document_id = $_POST['document_id'] ?? 0;

    // 1. Fetch document record
    $stmt = $pdo->prepare("SELECT title, subject_id, file_path, type FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $doc = $stmt->fetch();

    if (!$doc) {
        echo json_encode(['status' => 'error', 'message' => 'Document not found.']);
        exit;
    }

    // 2. Extract text using the unified helper
    $filePath = '../' . $doc['file_path'];
    if (!file_exists($filePath)) {
        echo json_encode(['status' => 'error', 'message' => 'File not found on disk: ' . $doc['file_path']]);
        exit;
    }

    $text = extract_text_from_file($filePath);

    if (strlen(trim($text)) < 80) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Could not extract enough text from this document (' . strtoupper($doc['type']) . '). '
                       . 'If it is a scanned/image-based PDF, text extraction is not possible. '
                       . 'Please upload a text-based DOCX, PPTX, or a text-based PDF.'
        ]);
        exit;
    }

    // 3. Generate questions
    $targetCount   = (int)($_POST['question_count'] ?? 30);
    $targetCount   = max(5, min($targetCount, 50)); // clamp 5–50
    $generated_qa  = generate_questions_from_text($text, $targetCount);

    if (count($generated_qa) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'No questions could be generated. The document may not contain enough structured sentences.']);
        exit;
    }

    // 4. Create the exam record
    $examTitle = 'Auto-Exam: ' . $doc['title'];
    $duration  = 60 + (count($generated_qa) * 2); // ~2 min per question

    $stmt = $pdo->prepare("INSERT INTO exams (title, subject_id, created_by, time_limit_minutes) VALUES (?, ?, ?, ?)");
    $stmt->execute([$examTitle, $doc['subject_id'], $teacher_id, $duration]);
    $exam_id = $pdo->lastInsertId();

    // 5. Insert questions
    $qStmt = $pdo->prepare(
        "INSERT INTO questions (exam_id, question_text, option_a, option_b, option_c, option_d, correct_option) "
      . "VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($generated_qa as $q) {
        // Safety: ensure opts array has exactly 4 elements
        $opts = array_pad((array)$q['opts'], 4, 'N/A');
        $qStmt->execute([
            $exam_id,
            $q['q'],
            $opts[0], $opts[1], $opts[2], $opts[3],
            $q['correct'],
        ]);
    }

    echo json_encode([
        'status'              => 'success',
        'exam_id'             => $exam_id,
        'questions_generated' => count($generated_qa),
        'doc_title'           => $doc['title'],
    ]);
    exit;
}

if ($action === 'view_exam') {
    $exam_id = $_GET['exam_id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT title FROM exams WHERE id = ? AND created_by = ?");
    $stmt->execute([$exam_id, $teacher_id]);
    $exam = $stmt->fetch();
    
    if (!$exam) {
        echo json_encode(['status' => 'error', 'message' => 'Exam not found']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT question_text, option_a, option_b, option_c, option_d, correct_option FROM questions WHERE exam_id = ?");
    $stmt->execute([$exam_id]);
    
    echo json_encode(['status' => 'success', 'exam_title' => $exam['title'], 'questions' => $stmt->fetchAll()]);
    exit;
}

// Helpers are now in extract_text.php and generate_questions.php

if ($action === 'get_exams') {
    $stmt = $pdo->prepare("
        SELECT e.id, e.title, e.time_limit_minutes, s.name as subject_name 
        FROM exams e 
        JOIN subjects s ON e.subject_id = s.id 
        WHERE e.created_by = ? 
        ORDER BY e.id DESC
    ");
    $stmt->execute([$teacher_id]);
    $exams = $stmt->fetchAll();
    echo json_encode(['status' => 'success', 'exams' => $exams]);
    exit;
}

if ($action === 'delete_exam') {
    $exam_id = $_POST['exam_id'] ?? 0;
    // Questions are cascade deleted if foreign keys are setup, but we'll do manual delete just in case
    $stmt = $pdo->prepare("DELETE FROM questions WHERE exam_id = ?");
    $stmt->execute([$exam_id]);
    
    $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ? AND created_by = ?");
    $stmt->execute([$exam_id, $teacher_id]);
    
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'get_rankings') {
    $subject_id = $_GET['subject_id'] ?? 0;
    $category_id = $_GET['category_id'] ?? 0;
    
    if ($subject_id > 0) {
        $stmt = $pdo->prepare("
            SELECT u.name, s.score, s.total, s.taken_at, sub.name as subject_name
            FROM student_exam_scores s
            JOIN exams e ON s.exam_id = e.id
            JOIN users u ON s.student_id = u.id
            JOIN subjects sub ON e.subject_id = sub.id
            WHERE e.subject_id = ?
            ORDER BY s.score DESC, s.taken_at ASC
        ");
        $stmt->execute([$subject_id]);
    } else {
        $typeMap = [1 => 'General-Education', 2 => 'Major', 3 => 'Professional-Education'];
        $categoryType = $typeMap[$category_id] ?? '';
        
        $stmt = $pdo->prepare("
            SELECT u.name, s.score, s.total, s.taken_at, sub.name as subject_name
            FROM student_exam_scores s
            JOIN exams e ON s.exam_id = e.id
            JOIN users u ON s.student_id = u.id
            JOIN subjects sub ON e.subject_id = sub.id
            WHERE sub.type = ?
            ORDER BY s.score DESC, s.taken_at ASC
        ");
        $stmt->execute([$categoryType]);
    }
    echo json_encode(['status' => 'success', 'rankings' => $stmt->fetchAll()]);
    exit;
}

if ($action === 'create_scheduled_exam') {
    $subject_id = $_POST['subject_id'] ?? 0;
    
    // Check for NEW subject
    $new_subject_name = $_POST['new_subject_name'] ?? '';
    if ($subject_id == 0 && !empty($new_subject_name)) {
        $category_id = $_POST['category_id'] ?? 1;
        $typeMap = [1 => 'General-Education', 2 => 'Major', 3 => 'Professional-Education'];
        $catType = $typeMap[$category_id] ?? 'General-Education';
        
        $stmt = $pdo->prepare("INSERT INTO subjects (name, type) VALUES (?, ?)");
        $stmt->execute([$new_subject_name, $catType]);
        $subject_id = $pdo->lastInsertId();
    }
    
    $title = $_POST['title'] ?? 'Custom Scheduled Exam';
    $schedule = $_POST['examSchedule'] ?? null; // datetime-local format is YYYY-MM-DDTHH:MM
    if (!$schedule) $schedule = null;

    if ($subject_id > 0) {
        $stmt = $pdo->prepare("INSERT INTO exams (title, subject_id, created_by, time_limit_minutes, scheduled_date) VALUES (?, ?, ?, 60, ?)");
        $stmt->execute([$title, $subject_id, $teacher_id, $schedule]);
        
        // Notify all students if scheduled
        if ($schedule) {
            // Find subject name for notification
            $stmtSub = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
            $stmtSub->execute([$subject_id]);
            $sub = $stmtSub->fetch();
            $subName = $sub['name'] ?? 'Subject';

            $formattedDate = date("F j, Y, g:i a", strtotime($schedule));
            $message = "A new scheduled exam '{$title}' for {$subName} has been set for {$formattedDate}.";
            
            // Get all students
            $stmtUsers = $pdo->prepare("SELECT id FROM users WHERE role = 'student'");
            $stmtUsers->execute();
            $students = $stmtUsers->fetchAll();
            
            $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            foreach ($students as $student) {
                $stmtNotif->execute([$student['id'], $message]);
            }
        }

        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid subject.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
