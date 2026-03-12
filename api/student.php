<?php
header('Content-Type: application/json');
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
require_once 'db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'get_exam') {
    $subject = $_GET['subject'] ?? '';
    if (!$subject) {
        echo json_encode(['status' => 'error', 'message' => 'Missing subject']);
        exit;
    }

    $typeMap = [
        'major' => 'Major',
        'prof' => 'Professional-Education',
        'nonprof' => 'General-Education'
    ];

    $subjectType = $typeMap[$subject] ?? '';

    $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE type = ? LIMIT 1");
    $stmt->execute([$subjectType]);
    $sub = $stmt->fetch();

    if (!$sub) {
        echo json_encode(['status' => 'error', 'message' => 'Subject not found in DB']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, title FROM exams e JOIN subjects s ON e.subject_id = s.id WHERE s.type = ? LIMIT 1");
    $stmt->execute([$subjectType]);
    $exam = $stmt->fetch();

    $questions = [];
    if ($exam) {
        $stmt = $pdo->prepare("SELECT id, question_text, option_a, option_b, option_c, option_d, correct_option FROM questions WHERE exam_id = ?");
        $stmt->execute([$exam['id']]);
        $qs = $stmt->fetchAll();

        foreach ($qs as $q) {
            $cMap = ['a' => 0, 'b' => 1, 'c' => 2, 'd' => 3];
            $questions[] = [
                'id' => $q['id'],
                'q' => $q['question_text'],
                'a' => [$q['option_a'], $q['option_b'], $q['option_c'], $q['option_d']],
                'c' => $cMap[$q['correct_option']] ?? 0
            ];
        }
    }

    // Mock questions if database is empty for this exam.
    if (empty($questions)) {
        $examTitle = $exam ? $exam['title'] : "Demo Exam for " . $subjectType;
        $questions = [
            [
                'id' => 1,
                'q' => 'This is a mock question 1 for ' . $subjectType . ' (System will fetch real ones once added by teacher)',
                'a' => ['Option A', 'Option B', 'Option C', 'Option D'],
                'c' => 0
            ],
            [
                'id' => 2,
                'q' => 'This is a mock question 2 for ' . $subjectType,
                'a' => ['Option A', 'Option B', 'Option C', 'Option D'],
                'c' => 1
            ],
            [
                'id' => 3,
                'q' => 'This is a mock question 3 for ' . $subjectType,
                'a' => ['Option A', 'Option B', 'Option C', 'Option D'],
                'c' => 2
            ]
        ];
    } else {
        $examTitle = $exam['title'];
    }

    echo json_encode([
        'status' => 'success',
        'exam' => [
            'id' => $exam['id'] ?? null,
            'title' => $examTitle,
            'questions' => $questions
        ]
    ]);
    exit;
}

$username = $_GET['username'] ?? $_POST['username'] ?? '';
$student_id = 0;
if ($username) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u) {
        $student_id = $u['id'];
    }
}

if ($action === 'get_documents') {
    $subject = $_GET['subject'] ?? '';
    
    $typeMap = ['major' => 'Major', 'prof' => 'Professional-Education', 'nonprof' => 'General-Education'];
    $subjectType = $typeMap[$subject] ?? '';
    
    $stmt = $pdo->prepare("
        SELECT d.id, d.title, d.type, d.file_path, IFNULL(p.progress_percentage, 0) as progress
        FROM documents d
        JOIN subjects sub ON d.subject_id = sub.id
        LEFT JOIN student_reading_progress p ON d.id = p.document_id AND p.student_id = ?
        WHERE sub.type = ?
    ");
    $stmt->execute([$student_id, $subjectType]);
    $docs = $stmt->fetchAll();
    echo json_encode(['status' => 'success', 'documents' => $docs]);
    exit;
}

if ($action === 'update_progress') {
    $document_id = $_POST['document_id'] ?? 0;
    $progress = $_POST['progress_percentage'] ?? 0;
    $last_position = $_POST['last_position'] ?? 0;

    if ($student_id && $document_id) {
        $stmt = $pdo->prepare("
            INSERT INTO student_reading_progress (student_id, document_id, progress_percentage, last_position)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                progress_percentage = GREATEST(progress_percentage, VALUES(progress_percentage)), 
                last_position = VALUES(last_position)
        ");
        $stmt->execute([$student_id, $document_id, $progress, $last_position]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid student or document']);
    }
    exit;
}

if ($action === 'get_progress') {
    $document_id = $_GET['document_id'] ?? 0;

    if ($student_id && $document_id) {
        $stmt = $pdo->prepare("SELECT progress_percentage, last_position FROM student_reading_progress WHERE student_id = ? AND document_id = ?");
        $stmt->execute([$student_id, $document_id]);
        $prog = $stmt->fetch();

        if ($prog) {
            echo json_encode(['status' => 'success', 'progress' => $prog['progress_percentage'], 'last_position' => $prog['last_position']]);
        } else {
            echo json_encode(['status' => 'success', 'progress' => 0, 'last_position' => 0]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid student or document']);
    }
    exit;
}

if ($action === 'get_profile') {
    if ($student_id) {
        // Fetch last 5 exam scores
        $stmt = $pdo->prepare("
            SELECT s.score, s.total, e.title, sub.type as subject_type 
            FROM student_exam_scores s
            JOIN exams e ON s.exam_id = e.id
            JOIN subjects sub ON e.subject_id = sub.id
            WHERE s.student_id = ?
            ORDER BY s.taken_at DESC LIMIT 5
        ");
        $stmt->execute([$student_id]);
        $scores = $stmt->fetchAll();

        // Fetch unread notifications
        $stmt = $pdo->prepare("SELECT message, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC");
        $stmt->execute([$student_id]);
        $notifs = $stmt->fetchAll();

        echo json_encode(['status' => 'success', 'scores' => $scores, 'notifications' => $notifs]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid student']);
    }
    exit;
}

if ($action === 'submit_exam') {
    $exam_id = $_POST['exam_id'] ?? 0;
    $score = $_POST['score'] ?? 0;
    $total = $_POST['total'] ?? 0;

    if ($student_id && $exam_id) {
        $stmt = $pdo->prepare("INSERT INTO student_exam_scores (student_id, exam_id, score, total) VALUES (?, ?, ?, ?)");
        $stmt->execute([$student_id, $exam_id, $score, $total]);
        
        // Also notify the student
        $stmt2 = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt2->execute([$student_id, "You scored $score out of $total on your recent exam."]);

        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid exam or student']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
