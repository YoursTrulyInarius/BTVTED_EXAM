/**
 * BTVTED Exam Logic
 */

// Exam State Management
let currentExam = {
    subjectKey: '',
    questions: [],
    userAnswers: {},
    timeLeft: 3600,
    timerInterval: null
};

/**
 * Initialize Exam
 */
async function initExam() {
    const sub = localStorage.getItem("selectedSubject");
    const examId = localStorage.getItem("selectedExamId"); // New
    
    if (!sub && !examId) {
        Swal.fire({
            title: "Access Error",
            text: "No exam or subject selected. Returning to dashboard.",
            icon: "error"
        }).then(() => {
            window.location.href = "dashboard.html";
        });
        return;
    }

    currentExam.subjectKey = sub;

    try {
        // Fetch specific exam if ID is known, else fallback to random subject exam
        const url = examId 
            ? `api/student.php?action=get_exam&exam_id=${examId}`
            : `api/student.php?action=get_exam&subject=${sub}`;
            
        const response = await fetch(url);
        const data = await response.json();

        if (data.status === 'success' && data.exam) {
            currentExam.id = data.exam.id;
            currentExam.questions = data.exam.questions;
            
            // Set Time Left based on exam specific limits
            currentExam.timeLeft = (data.exam.time_limit_minutes || 60) * 60;
            
            // UI Setup
            document.getElementById("current-subject-title").textContent = data.exam.title;
            document.getElementById("display-subject-name").textContent = "BTVTED " + (sub === 'major' ? 'Major' : sub === 'prof' ? 'Prof Ed' : 'Gen Ed');
            document.getElementById("total-num").textContent = currentExam.questions.length;
            document.getElementById("res-total").textContent = currentExam.questions.length;

            renderQuestions();
            startTimer();

            // Guard: Prevent leaving
            window.onbeforeunload = () => "Are you sure you want to leave? Your progress will be lost.";
        } else {
            Swal.fire("Load Error", "Failed to load exam data: " + (data.message || "Unknown error"), "error").then(() => {
                window.location.href = "exams.html";
            });
        }
    } catch (e) {
        console.error(e);
        Swal.fire("Error", "Error fetching exam. Returning to dashboard.", "error").then(() => {
            window.location.href = "exams.html";
        });
    }
}

/**
 * Render Questions to DOM
 */
function renderQuestions() {
    const container = document.getElementById("questions-container");
    container.innerHTML = "";

    currentExam.questions.forEach((item, idx) => {
        const qCard = document.createElement("div");
        qCard.className = "question-card";
        qCard.id = `q-card-${idx}`;
        
        qCard.innerHTML = `
            <div class="question-number">Question ${idx + 1}</div>
            <div class="question-text">${item.q}</div>
            <div class="options-list">
                ${item.a.map((opt, i) => `
                    <div class="option-item">
                        <input type="radio" name="question-${idx}" id="q${idx}-o${i}" value="${i}" onchange="selectAnswer(${idx}, ${i})">
                        <label for="q${idx}-o${i}">
                            <div class="radio-dot"></div>
                            <div class="option-letter">${String.fromCharCode(65 + i)}</div>
                            <span>${opt}</span>
                        </label>
                    </div>
                `).join('')}
            </div>
        `;
        container.appendChild(qCard);
    });
}

/**
 * Handle Answer Selection
 */
function selectAnswer(qIdx, ansIdx) {
    currentExam.userAnswers[qIdx] = ansIdx;
    
    // Update Progress
    const answeredCount = Object.keys(currentExam.userAnswers).length;
    document.getElementById("answered-num").textContent = answeredCount;
    
    const progress = (answeredCount / currentExam.questions.length) * 100;
    document.getElementById("progress-bar").style.width = progress + "%";

    // Mark card as answered
    document.getElementById(`q-card-${qIdx}`).classList.add("answered");
}

/**
 * Timer Logic
 */
function startTimer() {
    const timerEl = document.getElementById("timer");
    const timerBox = document.getElementById("timer-box");

    currentExam.timerInterval = setInterval(() => {
        currentExam.timeLeft--;

        if (currentExam.timeLeft <= 0) {
            clearInterval(currentExam.timerInterval);
            finishExam();
            return;
        }

        const mins = Math.floor(currentExam.timeLeft / 60);
        const secs = currentExam.timeLeft % 60;
        timerEl.textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;

        // Dynamic Warnings
        if (currentExam.timeLeft < 300) { // 5 mins
            timerBox.className = "timer-display danger";
        } else if (currentExam.timeLeft < 900) { // 15 mins
            timerBox.className = "timer-display warning";
        }
    }, 1000);
}

/**
 * Finish and Calculate Score
 */
function finishExam() {
    clearInterval(currentExam.timerInterval);
    window.onbeforeunload = null;

    let correct = 0;
    let wrong = 0;

    currentExam.questions.forEach((q, idx) => {
        if (currentExam.userAnswers[idx] === q.c) {
            correct++;
        } else {
            wrong++;
        }
    });

    // Show Results
    document.getElementById("finalScore").textContent = `${correct} / ${currentExam.questions.length}`;
    document.getElementById("res-correct").textContent = correct;
    document.getElementById("res-wrong").textContent = wrong;
    
    // Submit to DB
    const studentName = localStorage.getItem("studentName");
    if (studentName) {
        const formData = new FormData();
        formData.append('action', 'submit_exam');
        formData.append('username', localStorage.getItem('username'));
        formData.append('exam_id', currentExam.id || 1); // fallback if not set
        formData.append('score', correct);
        formData.append('total', currentExam.questions.length);

        fetch('api/student.php', { method: 'POST', body: formData }).catch(console.error);
    }
    
    const scorePct = (correct / currentExam.questions.length) * 100;
    const icon = document.getElementById("resultIcon");
    const title = document.getElementById("resultTitle");

    if (scorePct >= 75) {
        icon.textContent = "🏆";
        icon.className = "result-icon pass";
        title.textContent = "Excellent Work!";
    } else if (scorePct >= 50) {
        icon.textContent = "👍";
        icon.className = "result-icon pass";
        title.textContent = "Good Effort!";
    } else {
        icon.textContent = "⚠️";
        icon.className = "result-icon fail";
        title.textContent = "Needs More Practice";
    }

    document.getElementById("resultModal").classList.add("active");
}

// Event Listeners
document.getElementById("submitExamBtn").addEventListener("click", () => {
    const answered = Object.keys(currentExam.userAnswers).length;
    const total = currentExam.questions.length;
    
    if (answered < total) {
        Swal.fire({
            title: 'Are you sure?',
            text: `You have only answered ${answered} out of ${total} questions.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, submit anyway!'
        }).then((result) => {
            if (result.isConfirmed) {
                finishExam();
            }
        });
    } else {
        finishExam();
    }
});

// Run Init
window.onload = initExam;