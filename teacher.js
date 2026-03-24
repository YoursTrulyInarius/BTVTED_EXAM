// Guard
const userRole = localStorage.getItem("userRole");
const username = localStorage.getItem("username");
const teacherName = localStorage.getItem("studentName"); // Re-using studentName for simplicity

if (userRole !== 'teacher' || !username) {
    window.location.href = "index.html";
}

document.getElementById("teacherNameDisplay").textContent = teacherName || "Teacher";

// Logout
document.getElementById("logoutBtn").addEventListener("click", function(e) {
    e.preventDefault();
    localStorage.clear();
    window.location.href = "index.html";
});

function switchTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    
    // Find the button and add active class
    const btn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick').includes(`'${tabId}'`));
    if (btn) btn.classList.add('active');
    
    const content = document.getElementById(`tab-${tabId}`);
    if (content) content.classList.add('active');

    // Persist
    localStorage.setItem("teacherActiveTab", tabId);

    if (tabId === 'upload') {
        fetchTeacherDocs();
    } else if (tabId === 'exams') {
        fetchExams();
    } else if (tabId === 'students') {
        fetchRankings();
    }
}

// Upload Handling
document.getElementById("uploadForm").addEventListener("submit", async function (e) {
    e.preventDefault();
    const btn = document.getElementById("uploadBtn");
    const msg = document.getElementById("uploadMsg");
    const formData = new FormData(this);
    formData.append('action', 'upload_doc');
    formData.append('username', username);
    
    // Resolve subject ID from datalist
    const docInput = document.getElementById("docSpecificSubject");
    const categoryId = document.getElementById("docSubject").value;
    const options = document.querySelectorAll("#docSubjectOptions option");
    let subjectId = 0;
    
    options.forEach(opt => {
        if (opt.value === docInput.value) {
            subjectId = opt.dataset.id;
        }
    });

    if (subjectId > 0) {
        formData.append('subject_id', subjectId);
    } else {
        formData.append('new_subject_name', docInput.value);
        formData.append('category_id', categoryId);
    }

    btn.textContent = "Uploading...";
    btn.disabled = true;

    try {
        const res = await fetch('api/teacher.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            Swal.fire("Success", "Document uploaded successfully!", "success");
            this.reset();
            fetchTeacherDocs();
        } else {
            Swal.fire("Error", data.message || "Upload failed", "error");
        }
    } catch (e) {
        Swal.fire("Error", "Server error. Try again.", "error");
    } finally {
        btn.textContent = "Upload Document";
        btn.disabled = false;
    }
});

// Fetch Rankings
async function fetchRankings() {

// Create Scheduled Exam
document.getElementById("createExamForm").addEventListener("submit", async function(e) {
    e.preventDefault();
    const btn = document.getElementById("createExamBtn");
    const formData = new FormData(this);
    formData.append('action', 'create_scheduled_exam');
    formData.append('username', username);

    // Resolve subject ID
    const examInput = document.getElementById("examSpecificSubject");
    const categoryId = document.getElementById("examSubjectCat").value;
    const options = document.querySelectorAll("#examSubjectOptions option");
    let subjectId = 0;
    
    options.forEach(opt => {
        if (opt.value === examInput.value) subjectId = opt.dataset.id;
    });

    if (subjectId > 0) {
        formData.append('subject_id', subjectId);
    } else {
        formData.append('new_subject_name', examInput.value);
        formData.append('category_id', categoryId);
    }

    btn.textContent = "Creating...";
    btn.disabled = true;

    try {
        const res = await fetch('api/teacher.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.status === 'success') {
            Swal.fire("Success", "Exam created successfully! Students will be notified.", "success");
            this.reset();
            fetchExams();
        } else {
            Swal.fire("Error", data.message || "Failed to create exam", "error");
        }
    } catch (e) {
        Swal.fire("Error", "Server error. Try again.", "error");
    } finally {
        btn.textContent = "Create Exam";
        btn.disabled = false;
    }
});
    const tbody = document.querySelector("#rankingsTable tbody");
    const categoryId = document.getElementById("rankSubject").value;
    const rankInput = document.getElementById("rankSpecificSubject");
    
    if (!categoryId) {
        tbody.innerHTML = "<tr><td colspan='5' style='text-align: center; color: #6b7280; padding: 40px;'>Please select a category to view rankings.</td></tr>";
        return;
    }

    // Resolve subject ID
    let subjectId = 0;
    const options = document.querySelectorAll("#rankSubjectOptions option");
    options.forEach(opt => {
        if (opt.value === rankInput.value) subjectId = opt.dataset.id;
    });

    // Remove "Loading..." to make it feel more real-time
    // tbody.innerHTML = "<tr><td colspan='4' style='text-align: center;'>Loading...</td></tr>";

    try {
        const res = await fetch(`api/teacher.php?action=get_rankings&category_id=${categoryId}&subject_id=${subjectId}&username=${username}`);
        const data = await res.json();

        if (data.status === 'success') {
            tbody.innerHTML = "";
            if (!data.rankings || data.rankings.length === 0) {
                tbody.innerHTML = "<tr><td colspan='5' style='text-align: center; color: #6b7280; padding: 40px;'>No rankings available for this selection.</td></tr>";
                return;
            }

            data.rankings.forEach((r, idx) => {
                const tr = document.createElement("tr");
                tr.innerHTML = `
                    <td><strong style="color: #6366f1;">#${idx + 1}</strong></td>
                    <td style="font-weight: bold; color: #374151;">${r.name}</td>
                    <td style="color: #4b5563;">${r.subject_name}</td>
                    <td><span class="score-badge">${r.score} / ${r.total}</span></td>
                    <td style="color: #6b7280; font-size: 0.9rem;">${new Date(r.taken_at).toLocaleString()}</td>
                `;
                tbody.appendChild(tr);
            });
        }
    } catch (e) {
        tbody.innerHTML = "<tr><td colspan='5' style='text-align: center; color: #ef4444;'>Failed to fetch data.</td></tr>";
    }
}

// Fetch and Manage Documents
async function fetchTeacherDocs() {
    const list = document.getElementById("teacherDocsList");
    const categoryId = document.getElementById("docSubject").value;
    const docInput = document.getElementById("docSpecificSubject");
    
    if (!categoryId) {
        list.innerHTML = "<p style='text-align: center; color: #6b7280; margin-top: 40px;'>Select a category to view documents.</p>";
        return;
    }

    // Find subject ID from text input/datalist
    let subjectId = 0;
    const options = document.querySelectorAll("#docSubjectOptions option");
    options.forEach(opt => {
        if (opt.value === docInput.value) subjectId = opt.dataset.id;
    });

    // Remove loading for real-time feel
    // list.innerHTML = "<p style='text-align:center;'>Loading...</p>";

    try {
        const url = subjectId > 0 
            ? `api/teacher.php?action=get_documents&subject_id=${subjectId}&username=${username}`
            : `api/teacher.php?action=get_documents&category_id=${categoryId}&username=${username}`;
            
        const res = await fetch(url);
        const data = await res.json();

        if (data.status === 'success') {
            list.innerHTML = "";
            if (!data.documents || data.documents.length === 0) {
                list.innerHTML = "<p style='text-align: center; color: #6b7280; margin-top: 40px;'>No documents found for this subject.</p>";
                return;
            }

            data.documents.forEach(doc => {
                const item = document.createElement("div");
                item.style = "display: flex; justify-content: space-between; align-items: center; padding: 15px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 10px;";
                
                item.innerHTML = `
                    <div style="flex: 1; margin-right: 15px;">
                        <strong style="display: block; color: #374151;">${doc.title}</strong>
                        <span style="font-size: 0.85rem; color: #6b7280; text-transform: uppercase;">${doc.type}</span>
                        <p style="font-size: 0.9rem; color: #4b5563; margin: 5px 0 0 0;">${doc.description || 'No detailed description.'}</p>
                    </div>
                    <div style="display: flex; gap: 8px; align-items: center; flex-direction: column;">
                        <button onclick="viewTeacherDocument(${doc.id}, '${doc.file_path}', '${doc.type}', '${doc.title.replace(/'/g, "\\'")}')" style="background: var(--primary); color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; width: 100%;">View File</button>
                        <button onclick="autoGenerateExam(${doc.id}, '${doc.title.replace(/'/g, "\\'")}')" style="background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; width: 100%;">Create Auto Exam</button>
                        <button onclick="deleteDocument(${doc.id})" style="background: #ef4444; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; width: 100%;">Delete File</button>
                    </div>
                `;
                list.appendChild(item);
            });
        } else {
            list.innerHTML = `<p style="color:red; text-align:center;">${data.message}</p>`;
        }
    } catch (e) {
        list.innerHTML = "<p style='color:red; text-align:center;'>Failed to fetch documents.</p>";
    }
}

function viewTeacherDocument(id, path, type, title) {
    localStorage.setItem("viewDocId", id);
    localStorage.setItem("viewDocPath", path);
    localStorage.setItem("viewDocType", type);
    localStorage.setItem("viewDocTitle", title);
    window.location.href = "viewer.html";
}

function deleteDocument(id) {
    Swal.fire({
        title: 'Delete Document?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!'
    }).then(async (result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete_doc');
            formData.append('document_id', id);
            formData.append('username', username);

            try {
                const res = await fetch('api/teacher.php', { method: 'POST', body: formData });
                const data = await res.json();
                
                if (data.status === 'success') {
                    Swal.fire('Deleted!', 'Document has been deleted.', 'success');
                    fetchTeacherDocs();
                } else {
                    Swal.fire('Error', data.message || "Failed to delete document.", 'error');
                }
            } catch (e) {
                Swal.fire('Error', "Server error deleting document.", 'error');
            }
        }
    });
}

function autoGenerateExam(docId, docTitle) {
    Swal.fire({
        title: 'Generate Exam from Document?',
        html: `<p>The system will read <strong>"${docTitle}"</strong>, extract its text, and automatically generate multiple-choice questions.</p>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '✨ Yes, Generate!'
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: '📄 Reading Document...',
                html: 'Extracting text and building intelligent questions.<br><small style="color:#6b7280;">This may take 5–15 seconds.</small>',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            const formData = new FormData();
            formData.append('action', 'auto_generate_exam');
            formData.append('document_id', docId);
            formData.append('username', username);
            formData.append('question_count', 30);

            try {
                const res = await fetch('api/teacher.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: '✅ Exam Created!',
                        html: `<b>${data.questions_generated} questions</b> were generated from<br>"${data.doc_title}".<br><br><span style="color:#6b7280;font-size:0.9rem;">Open the <b>Exam Mgmt</b> tab to review and publish.</span>`
                    }).then(() => {
                        switchTab('exams');
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Generation Failed',
                        html: data.message || 'An unknown error occurred.',
                    });
                }
            } catch (e) {
                Swal.fire('Error', 'Server error. Check if XAMPP is running and try again.', 'error');
            }
        }
    });
}

async function fetchExams() {
    const list = document.getElementById("teacherExamsList");
    // list.innerHTML = "<p style='text-align:center;'>Loading Exams...</p>";

    try {
        const res = await fetch(`api/teacher.php?action=get_exams&username=${username}`);
        const data = await res.json();

        if (data.status === 'success') {
            list.innerHTML = "";
            if (!data.exams || data.exams.length === 0) {
                list.innerHTML = "<p style='text-align: center; color: #6b7280; margin-top: 40px;'>No exams available yet.</p>";
                return;
            }

            data.exams.forEach(ex => {
                const item = document.createElement("div");
                item.style = "display: flex; justify-content: space-between; align-items: center; padding: 15px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 10px;";
                
                item.innerHTML = `
                    <div style="flex: 1; margin-right: 15px;">
                        <strong style="display: block; color: var(--text-dark);">${ex.title}</strong>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Subject: ${ex.subject_name} | Duration: ${ex.time_limit_minutes} mins</span>
                        ${ex.scheduled_date ? `<div style="font-size: 0.8rem; color: var(--primary); margin-top: 5px;">📅 Scheduled: ${new Date(ex.scheduled_date).toLocaleString()}</div>` : ''}
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button onclick="viewExam(${ex.id})" class="btn-primary" style="padding: 6px 12px; font-size: 0.85rem;">View Exam</button>
                        <button onclick="deleteExam(${ex.id})" class="btn-danger" style="padding: 6px 12px; font-size: 0.85rem;">Delete</button>
                    </div>
                `;
                list.appendChild(item);
            });
        }
    } catch (e) {
        list.innerHTML = "<p style='color:red; text-align:center;'>Failed to fetch exams.</p>";
    }
}

function deleteExam(id) {
    Swal.fire({
        title: 'Delete Exam?',
        text: "Are you sure you want to delete this exam?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!'
    }).then(async (result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'delete_exam');
            formData.append('exam_id', id);
            formData.append('username', username);

            try {
                const res = await fetch('api/teacher.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.status === 'success') {
                    Swal.fire('Deleted!', 'Exam deleted.', 'success');
                    fetchExams();
                } else {
                    Swal.fire('Error', data.message, 'error');
                }
            } catch(e) {
                Swal.fire('Error', 'Server error.', 'error');
            }
        }
    });
}

async function viewExam(id) {
    const modal = document.getElementById('examModal');
    const title = document.getElementById('examModalTitle');
    const content = document.getElementById('examModalContent');
    
    modal.classList.add('active');
    content.innerHTML = '<p>Loading questions...</p>';
    
    try {
        const res = await fetch(`api/teacher.php?action=view_exam&exam_id=${id}&username=${username}`);
        const data = await res.json();
        
        if (data.status === 'success') {
            title.textContent = data.exam_title;
            let html = "";
            
            data.questions.forEach((q, i) => {
                html += `
                    <div style="margin-bottom: 20px; padding: 15px; background: var(--secondary); border: 1px solid var(--border); border-radius: 8px;">
                        <strong style="display:block; margin-bottom: 10px;">${i + 1}. ${q.question_text}</strong>
                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 0.9rem; color: var(--text-muted);">
                            <li style="margin-bottom: 5px; ${q.correct_option === 'a' ? 'color: var(--primary); font-weight: bold;' : ''}">A. ${q.option_a}</li>
                            <li style="margin-bottom: 5px; ${q.correct_option === 'b' ? 'color: var(--primary); font-weight: bold;' : ''}">B. ${q.option_b}</li>
                            <li style="margin-bottom: 5px; ${q.correct_option === 'c' ? 'color: var(--primary); font-weight: bold;' : ''}">C. ${q.option_c}</li>
                            <li style="margin-bottom: 5px; ${q.correct_option === 'd' ? 'color: var(--primary); font-weight: bold;' : ''}">D. ${q.option_d}</li>
                        </ul>
                    </div>
                `;
            });
            content.innerHTML = html;
        } else {
            content.innerHTML = `<p style="color: red;">${data.message}</p>`;
        }
    } catch(e) {
        content.innerHTML = '<p style="color:red;">Error loading exam details.</p>';
    }
}

// Initialize
const savedTab = localStorage.getItem("teacherActiveTab") || 'upload';
switchTab(savedTab);
async function loadSpecificSubjects(prefix) {
    const categoryId = document.getElementById(prefix + "Subject").value;
    const target = document.getElementById(prefix + "SubjectOptions");
    const input = document.getElementById(prefix + "SpecificSubject");
    
    // Reset
    target.innerHTML = '';
    input.value = "";

    if (!categoryId) return;

    try {
        const res = await fetch(`api/teacher.php?action=get_subjects&category_id=${categoryId}&username=${username}`);
        const data = await res.json();
        
        if (data.status === 'success') {
            data.subjects.forEach(sub => {
                const opt = document.createElement("option");
                opt.value = sub.name;
                opt.dataset.id = sub.id;
                target.appendChild(opt);
            });
        }
    } catch (e) {
        console.error("Failed to load subjects", e);
    }

    // Trigger data fetch for rank or doc
    if (prefix === 'rank') fetchRankings();
    if (prefix === 'doc') fetchTeacherDocs();
}

// Show all options when clicking/focusing
['doc', 'rank'].forEach(prefix => {
    const input = document.getElementById(prefix + "SpecificSubject");
    input.addEventListener("input", () => {
        if (prefix === 'doc') fetchTeacherDocs();
        else fetchRankings();
    });
    input.addEventListener("focus", function() {
        this.setAttribute('placeholder', 'Search or type...');
    });
    input.addEventListener("click", function() {
        if (this.value === "") {
            const val = this.value;
            this.value = ' ';
            this.value = val;
        }
    });
});


// Initial calls based on stored path
setTimeout(() => {
    const activeTab = localStorage.getItem("teacherActiveTab") || "upload";
    if (activeTab === "upload") loadSpecificSubjects('doc');
    else if (activeTab === "students") loadSpecificSubjects('rank');
}, 100);
