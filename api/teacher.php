<?php
header('Content-Type: application/json');
require_once 'db.php';

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
    
    // 1. Fetch document
    $stmt = $pdo->prepare("SELECT title, subject_id FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $doc = $stmt->fetch();
    
    if (!$doc) {
        echo json_encode(['status' => 'error', 'message' => 'Document not found']);
        exit;
    }
    
    // 2. Extract Text
    $filePath = "../" . $doc['file_path'];
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $text = "";
    
    if (file_exists($filePath)) {
        if ($ext === 'pptx') {
            $text = extract_text_from_pptx($filePath);
        } else if ($ext === 'docx') {
            $text = extract_text_from_docx($filePath);
        } else if ($ext === 'pdf') {
            $text = "PDF native parsing unavailable. Please use DOCX or PPTX for accurate generation. The system requires text-based documents to process sentences."; // Fallback message
        }
    }
    
    if (strlen(trim($text)) < 50) {
        $text = "Not enough text could be extracted from this document to generate a high quality exam. Please provide a rich text document. We are using fallback text to demonstrate the functionality.";
    }

    // 3. Create an exam
    $examTitle = "Auto-Exam: " . $doc['title'];
    $duration = ($doc['subject_id'] == 2) ? 120 : 90; // Major 120, others 90
    
    $stmt = $pdo->prepare("INSERT INTO exams (title, subject_id, created_by, time_limit_minutes) VALUES (?, ?, ?, ?)");
    $stmt->execute([$examTitle, $doc['subject_id'], $teacher_id, $duration]);
    $exam_id = $pdo->lastInsertId();
    
    // 4. Generate Questions based on extracted text
    $generated_qa = generate_questions_from_text($text, 30);
    
    $qStmt = $pdo->prepare("INSERT INTO questions (exam_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($generated_qa as $q) {
        $qStmt->execute([
            $exam_id,
            $q['q'],
            $q['opts'][0],
            $q['opts'][1],
            $q['opts'][2],
            $q['opts'][3],
            $q['correct']
        ]);
    }
    
    echo json_encode(['status' => 'success', 'exam_id' => $exam_id]);
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

// ================= Helpers =================

function extract_text_from_pptx($filePath) {
    $text = "";
    $zip = new ZipArchive;
    if ($zip->open($filePath) === TRUE) {
        $slideCount = 1;
        while (($xmlString = $zip->getFromName('ppt/slides/slide' . $slideCount . '.xml')) !== false) {
            $xml = new DOMDocument();
            @$xml->loadXML($xmlString);
            $texts = $xml->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't');
            foreach ($texts as $t) {
                $text .= $t->nodeValue . " ";
            }
            $slideCount++;
        }
        $zip->close();
    }
    return $text;
}

function extract_text_from_docx($filePath) {
    $text = "";
    $zip = new ZipArchive;
    if ($zip->open($filePath) === TRUE) {
        if (($xmlString = $zip->getFromName('word/document.xml')) !== false) {
            $xml = new DOMDocument();
            @$xml->loadXML($xmlString);
            $texts = $xml->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
            foreach ($texts as $t) {
                $text .= $t->nodeValue . " ";
            }
        }
        $zip->close();
    }
    return $text;
}

function generate_questions_from_text($text, $count = 30) {
    $sentences = preg_split('/(?<=[.!?])\s+/', $text);
    $valid_sentences = [];
    foreach ($sentences as $s) {
        $s = trim($s);
        $wordCount = str_word_count($s);
        // Look for descriptive sentences
        if ($wordCount >= 6 && $wordCount <= 30) {
            $valid_sentences[] = $s;
        }
    }
    
    shuffle($valid_sentences);
    $selected = array_slice($valid_sentences, 0, $count);
    
    // Extract distractors (words > 6 chars)
    preg_match_all('/\b[a-zA-Z]{6,}\b/', $text, $matches);
    $distractors = array_unique($matches[0]);
    if (count($distractors) < 10) {
        $distractors = array_merge($distractors, ['Information', 'System', 'Process', 'Component', 'Module', 'Structure', 'Concept', 'Analysis', 'Development', 'Strategy']);
    }
    
    $questions = [];
    $map = [0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'];
    
    foreach ($selected as $s) {
        preg_match_all('/\b[a-zA-Z]{5,}\b/', $s, $words);
        if (count($words[0]) > 0) {
            $answer = $words[0][array_rand($words[0])];
            $question_text = preg_replace('/\b' . preg_quote($answer, '/') . '\b/', '_____', $s, 1);
            
            $options = [$answer];
            $temp_distract = $distractors;
            shuffle($temp_distract);
            
            foreach ($temp_distract as $d) {
                if (strtolower($d) !== strtolower($answer) && count($options) < 4) {
                    $options[] = $d;
                }
            }
            
            while (count($options) < 4) { $options[] = "None of the above"; }
            
            shuffle($options);
            $correct_idx = array_search($answer, $options);
            
            $questions[] = [
                'q' => $question_text,
                'opts' => $options,
                'correct' => $map[$correct_idx]
            ];
        }
    }
    
    // Fill remaining with generic fallbacks if document was too short
    while (count($questions) < $count) {
        $questions[] = [
            'q' => "Please refer to the document to understand context. What is a key concept discussed?",
            'opts' => ["Primary Focus", "Secondary Element", "Irrelevant Detail", "Excluded Topic"],
            'correct' => 'a'
        ];
    }
    
    return array_slice($questions, 0, $count);
}

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

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
