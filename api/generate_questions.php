<?php
/**
 * generate_questions.php
 * Smart, no-API multiple-choice question generator.
 *
 * Strategy (in order of quality):
 *   1. Definition pattern   — "X is/are/refers to/defined as Y"
 *   2. Enumeration pattern  — "There are N …"
 *   3. Characteristic / adjective pattern — "X is [adj] …"
 *   4. Fill-intelligent     — pick the grammatical subject or object of a sentence,
 *                             use thematically-related terms as distractors
 *
 * Returns an array of:
 *   [ 'q' => string, 'opts' => [A,B,C,D], 'correct' => 'a'|'b'|'c'|'d' ]
 */

function generate_questions_from_text(string $text, int $targetCount = 30): array {
    $sentences = split_into_sentences($text);

    // Build a distractor pool from all key terms in the document
    $termPool = build_term_pool($text);

    $questions = [];
    $seen_q    = [];   // deduplication

    // ── Pass 1: definition patterns (highest quality) ──
    foreach ($sentences as $s) {
        if (count($questions) >= $targetCount) break;
        $q = try_definition_question($s, $termPool, $seen_q);
        if ($q) { $questions[] = $q; $seen_q[] = strtolower($q['q']); }
    }

    // ── Pass 2: enumeration patterns ──
    foreach ($sentences as $s) {
        if (count($questions) >= $targetCount) break;
        $q = try_enumeration_question($s, $termPool, $seen_q);
        if ($q) { $questions[] = $q; $seen_q[] = strtolower($q['q']); }
    }

    // ── Pass 3: characteristic / adjective pattern ──
    foreach ($sentences as $s) {
        if (count($questions) >= $targetCount) break;
        $q = try_characteristic_question($s, $termPool, $seen_q);
        if ($q) { $questions[] = $q; $seen_q[] = strtolower($q['q']); }
    }

    // ── Pass 4: intelligent fill-in-subject ──
    shuffle($sentences);
    foreach ($sentences as $s) {
        if (count($questions) >= $targetCount) break;
        $q = try_fill_subject_question($s, $termPool, $seen_q);
        if ($q) { $questions[] = $q; $seen_q[] = strtolower($q['q']); }
    }

    // ── Trim & shuffle ──
    shuffle($questions);
    return array_slice($questions, 0, $targetCount);
}

// ══════════════════════════════════════════════════════
//  PATTERN 1 — Definition Questions
//  "Curriculum development is the process of planning …"
//  → Q: What is curriculum development?  A: the process of planning …
// ══════════════════════════════════════════════════════
function try_definition_question(string $s, array $pool, array $seen): ?array {
    $patterns = [
        // X is/are defined as Y
        '/^(.{3,60}?)\s+(?:is|are)\s+defined\s+as\s+(.{10,})/i',
        // X refers to Y
        '/^(.{3,60}?)\s+refers?\s+to\s+(.{10,})/i',
        // X means Y
        '/^(.{3,60}?)\s+means?\s+(.{10,})/i',
        // X can be described as Y
        '/^(.{3,60}?)\s+(?:can\s+be|is)\s+described\s+as\s+(.{10,})/i',
        // X is the process/act/method/practice of Y
        '/^(.{3,60}?)\s+is\s+(?:the\s+)?(?:process|act|method|practice|science|art|study|field|concept|term|system|approach|set|type|form)\s+of\s+(.{10,})/i',
        // Simple: X is Y (where Y starts with "a"/"an"/"the")
        '/^(.{3,50}?)\s+(?:is|are)\s+((?:a|an|the)\s+.{10,})/i',
    ];

    foreach ($patterns as $pat) {
        if (preg_match($pat, trim($s), $m)) {
            $term   = trim($m[1]);
            $answer = trim(rtrim($m[2], '.,:;'));

            // Answer must be a meaningful phrase, not just stop words
            if (str_word_count($answer) < 2) continue;

            $questionText = "What is/are " . $term . "?";
            $key = strtolower($questionText);
            if (in_array($key, $seen)) continue;

            // Truncate answer to a reasonable option length
            $answer = truncate_option($answer, 80);

            $opts = build_options($answer, $pool, $term);
            if (!$opts) continue;

            [$shuffled, $correctLetter] = shuffle_options($opts, $answer);
            return make_question($questionText, $shuffled, $correctLetter);
        }
    }
    return null;
}

// ══════════════════════════════════════════════════════
//  PATTERN 2 — Enumeration Questions
//  "There are three types of assessment: …"
//  → Q: How many types of assessment are there?  A: Three
// ══════════════════════════════════════════════════════
function try_enumeration_question(string $s, array $pool, array $seen): ?array {
    // Match: "There are [number] [thing(s)]"
    $numWords = ['two','three','four','five','six','seven','eight','nine','ten',
                 'two','2','3','4','5','6','7','8','9','10'];
    $numPat   = '(' . implode('|', $numWords) . ')';

    if (preg_match('/there\s+are\s+' . $numPat . '\s+(.{3,50?}?)(?:\:|,|\.|$)/i', $s, $m)) {
        $number = ucfirst(strtolower($m[1]));
        $thing  = trim($m[2]);
        if (str_word_count($thing) < 1) return null;

        $questionText = "How many {$thing} are there according to the text?";
        $key = strtolower($questionText);
        if (in_array($key, $seen)) return null;

        // Generate close but wrong number distractors
        $numMap = [
            '2'=>['Three','Four','Five'], '3'=>['Two','Four','Six'],
            '4'=>['Three','Five','Six'], '5'=>['Three','Four','Six'],
            '6'=>['Four','Five','Seven'], '7'=>['Five','Six','Eight'],
            '8'=>['Six','Seven','Nine'], '9'=>['Seven','Eight','Ten'],
            '10'=>['Eight','Nine','Twelve'],
            'two'=>['Three','Four','Five'], 'three'=>['Two','Four','Six'],
            'four'=>['Three','Five','Six'], 'five'=>['Three','Four','Six'],
            'six'=>['Four','Five','Seven'], 'seven'=>['Five','Six','Eight'],
            'eight'=>['Six','Seven','Nine'], 'nine'=>['Seven','Eight','Ten'],
            'ten'=>['Eight','Nine','Twelve'],
        ];
        $key2 = strtolower($m[1]);
        $distractors = $numMap[$key2] ?? ['Two','Three','Four'];

        $opts = [$number, $distractors[0], $distractors[1], $distractors[2]];
        [$shuffled, $correctLetter] = shuffle_options($opts, $number);
        return make_question($questionText, $shuffled, $correctLetter);
    }
    return null;
}

// ══════════════════════════════════════════════════════
//  PATTERN 3 — Characteristic Questions
//  "Assessment is important because it measures …"
//  → Q: Which of the following best describes Assessment?
//       A: important because it measures …
// ══════════════════════════════════════════════════════
function try_characteristic_question(string $s, array $pool, array $seen): ?array {
    // Match: Subject (≤5 words) + "is/are" + adjective phrase
    if (!preg_match('/^(.{3,50}?)\s+(?:is|are)\s+([a-z][a-zA-Z ,]{5,80})/i', $s, $m)) return null;

    $subject = trim($m[1]);
    $desc    = trim(rtrim($m[2], '.,:;'));

    // Filter: subject must be a meaningful noun phrase (not a pronoun)
    $pronouns = ['it','this','that','they','he','she','we','i','you','which','what'];
    if (in_array(strtolower($subject), $pronouns)) return null;
    if (str_word_count($subject) > 6) return null;
    if (str_word_count($desc) < 3) return null;

    $questionText = "Which of the following best describes \"{$subject}\"?";
    $key = strtolower($questionText);
    if (in_array($key, $seen)) return null;

    $answer = truncate_option($desc, 80);
    $opts   = build_options($answer, $pool, $subject);
    if (!$opts) return null;

    [$shuffled, $correctLetter] = shuffle_options($opts, $answer);
    return make_question($questionText, $shuffled, $correctLetter);
}

// ══════════════════════════════════════════════════════
//  PATTERN 4 — Fill-the-Subject (intelligent)
//  Pick the first meaningful noun from the sentence as blank.
//  Use other noun-phrases from the document as distractors.
// ══════════════════════════════════════════════════════
function try_fill_subject_question(string $s, array $pool, array $seen): ?array {
    $wc = str_word_count($s);
    if ($wc < 8 || $wc > 45) return null;

    // Find candidate nouns: capitalized mid-sentence words (likely proper nouns / key terms)
    // or words that appear frequently in the pool (key terms)
    $topTerms = array_keys(array_slice($pool, 0, 50, true));

    $blank = null;
    foreach ($topTerms as $term) {
        // Term must appear in sentence (case-insensitive, whole word)
        if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $s)) {
            $blank = $term;
            break;
        }
    }

    // Fallback: pick the first long word after a verb or at beginning
    if (!$blank) {
        preg_match_all('/\b([A-Z][a-z]{4,})\b/', $s, $caps);
        if (!empty($caps[1])) {
            $firstWord = strtolower($s);
            // Prefer second+ occurrences of cap words (not sentence-start)
            foreach ($caps[1] as $cap) {
                $pos = strpos($s, $cap);
                if ($pos > 1) { $blank = $cap; break; }
            }
        }
    }

    if (!$blank || strlen($blank) < 4) return null;

    // Build question text
    $questionText = preg_replace('/\b' . preg_quote($blank, '/') . '\b/i', '_____', $s, 1);
    $questionText = "Complete the sentence: \"" . rtrim($questionText, '.') . ".\"";

    $key = strtolower($questionText);
    if (in_array($key, $seen)) return null;

    $answer = $blank;
    $opts   = build_options($answer, $pool, '');
    if (!$opts) return null;

    [$shuffled, $correctLetter] = shuffle_options($opts, $answer);
    return make_question($questionText, $shuffled, $correctLetter);
}

// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════

/**
 * Split text into clean, individual sentences.
 */
function split_into_sentences(string $text): array {
    // Normalize whitespace
    $text = preg_replace('/\s+/', ' ', $text);

    // Split on sentence-ending punctuation followed by space+Capital
    $raw = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $text);

    $valid = [];
    foreach ($raw as $s) {
        $s = trim($s);
        $wc = str_word_count($s);
        if ($wc >= 6 && $wc <= 60) {
            $valid[] = $s;
        }
    }
    return $valid;
}

/**
 * Build a frequency-sorted pool of key terms from the full document text.
 * Returns [ term => frequency ] sorted descending.
 */
function build_term_pool(string $text): array {
    // Extract all words with 4+ characters
    preg_match_all('/\b([a-zA-Z]{4,})\b/', $text, $m);
    $words = array_map('strtolower', $m[1]);

    // Remove stop words
    $stopWords = [
        'that','this','with','from','they','have','been','were','will','would',
        'their','there','which','about','when','what','also','more','such',
        'than','into','some','each','many','these','those','both','through',
        'then','them','very','just','only','over','after','before','being',
        'while','where','should','could','every','other','most','even','same',
        'like','here','well','know','does','said','come','made','make','take',
        'used','using','based','includes','between','different','important',
        'learning','students','student','teacher','teachers','education',
        'educational','academic','school','process','system','type','types',
        'example','examples','called','often','help','helps','refers','refer',
        'defined','definition','means','provide','provides','includes',
        'include','involves','involve','develop','develops','focus','focusing',
        'various','general','specific','among','within','across','along',
        'several','another','related','number','part','parts','form','forms',
        'area','areas','level','levels','work','works','unit','units',
        'skills','skill','knowledge','activities','activity','approach',
    ];

    $freq = [];
    foreach ($words as $w) {
        if (in_array($w, $stopWords)) continue;
        $freq[$w] = ($freq[$w] ?? 0) + 1;
    }
    arsort($freq);
    return $freq;
}

/**
 * Build 4 options: the correct answer + 3 thematic distractors.
 */
function build_options(string $answer, array $pool, string $excludeTerm): ?array {
    $answerLower = strtolower($answer);
    $excludeLower = strtolower($excludeTerm);

    $distractors = [];
    foreach ($pool as $term => $freq) {
        if (count($distractors) >= 3) break;
        if (strtolower($term) === $answerLower) continue;
        if (!empty($excludeLower) && strtolower($term) === $excludeLower) continue;
        if (similar_text(strtolower($term), $answerLower) / max(strlen($answerLower), 1) > 0.85) continue;
        // Prefer terms of similar "type" (single-word vs phrase)
        $distractors[] = ucfirst($term);
    }

    // Pad with generic academic distractors if pool is too small
    $generics = [
        'Assessment', 'Curriculum', 'Instruction', 'Methodology', 'Framework',
        'Objective', 'Competency', 'Evaluation', 'Strategy', 'Implementation',
        'Motivation', 'Reflection', 'Integration', 'Development', 'Engagement',
    ];
    foreach ($generics as $g) {
        if (count($distractors) >= 3) break;
        if (strtolower($g) === $answerLower) continue;
        if (!in_array($g, $distractors)) $distractors[] = $g;
    }

    if (count($distractors) < 3) return null;

    return [$answer, $distractors[0], $distractors[1], $distractors[2]];
}

/**
 * Shuffle options array, return [shuffled_opts, correct_letter].
 */
function shuffle_options(array $opts, string $answer): array {
    $map = [0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'];
    shuffle($opts);
    $idx = array_search($answer, $opts);
    if ($idx === false) {
        // Ensure answer is present
        $opts[0] = $answer;
        $idx = 0;
    }
    return [$opts, $map[$idx]];
}

/** Wrap everything into the standard question array. */
function make_question(string $q, array $opts, string $correct): array {
    return ['q' => $q, 'opts' => $opts, 'correct' => $correct];
}

/** Truncate a string to max chars, ending at a word boundary. */
function truncate_option(string $s, int $max): string {
    if (strlen($s) <= $max) return $s;
    $cut = substr($s, 0, $max);
    $lastSpace = strrpos($cut, ' ');
    return ($lastSpace !== false) ? substr($cut, 0, $lastSpace) . '...' : $cut . '...';
}
