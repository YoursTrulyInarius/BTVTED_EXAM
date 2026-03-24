// PDF.js worker setup
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

const docId = localStorage.getItem("viewDocId");
const docPath = localStorage.getItem("viewDocPath");
const docType = localStorage.getItem("viewDocType");
const docTitle = localStorage.getItem("viewDocTitle");
const username = localStorage.getItem("username");

if (!docId || !docPath || !username) {
    window.location.href = "dashboard.html";
}

document.getElementById("doc-title").textContent = docTitle || "Document";
const container = document.getElementById("viewer-container");
const innerContainer = document.getElementById("viewer-inner");
const loadingToast = document.getElementById("loadingToast");
const progressBar = document.getElementById("progress-bar");
const progressText = document.getElementById("progress-text");

let currentProgress = 0;
let saveTimer = null;
let isRestoringPosition = false;

async function goBack() {
    const userRole = localStorage.getItem("userRole");
    if (userRole !== "teacher") {
        // Force an immediate save before leaving
        await saveProgressToDB(currentProgress, container.scrollTop);
    }
    
    if (userRole === "teacher") {
        window.location.href = "dashboard_teacher.html";
    } else if (docId === "direct") {
        window.location.href = "dashboard.html";
    } else {
        window.location.href = "subject.html";
    }
}

// Fetch Initial Progress
fetch(`api/student.php?action=get_progress&document_id=${docId}&username=${username}`)
    .then(r => r.json())
    .then(data => {
        const startProgress = data.progress ? parseFloat(data.progress) : 0;
        const lastPosition = data.last_position ? parseInt(data.last_position) : 0;
        currentProgress = startProgress;
        updateProgressUI(startProgress);
        loadDocument(startProgress, lastPosition);
    })
    .catch(err => {
        console.error(err);
        loadDocument(0, 0);
    });

function loadDocument(startProgress, lastPosition) {
    if (docType === 'pdf') {
        loadPDF(lastPosition);
    } else {
        // Non-PDF Fallback
        loadingToast.style.display = 'none';
        innerContainer.innerHTML = `
            <div class="non-pdf-msg">
                <h2 style="margin-top:0;">Cannot Render ${docType.toUpperCase()} Natively</h2>
                <p>Please download the file to view it.</p>
                <a href="${docPath}" target="_blank" onclick="markAsRead()" style="display:inline-block; padding: 10px 20px; background: #10b981; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 15px;">Download File</a>
            </div>
        `;
    }
}

function markAsRead() {
    currentProgress = 100;
    updateProgressUI(100);
    saveProgressToDB(100, 0);
}

// PDF Rendering Logic
async function loadPDF(lastPosition) {
    console.log("Loading PDF: ", docPath);
    try {
        const loadingTask = pdfjsLib.getDocument(docPath);
        const pdf = await loadingTask.promise;
        
        loadingToast.textContent = `Rendering ${pdf.numPages} pages...`;
        
        for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            const page = await pdf.getPage(pageNum);
            const scale = 1.3;
            const viewport = page.getViewport({ scale: scale });
            
            const canvas = document.createElement('canvas');
            canvas.className = 'pdf-page';
            canvas.height = viewport.height;
            canvas.width = viewport.width;
            
            const context = canvas.getContext('2d');
            const renderContext = {
                canvasContext: context,
                viewport: viewport
            };
            
            innerContainer.appendChild(canvas);
            await page.render(renderContext).promise;
        }
        
        loadingToast.style.display = 'none';
        
        // Restore scroll position
        if (lastPosition > 0) {
            isRestoringPosition = true;
            container.scrollTop = lastPosition;
            setTimeout(() => { isRestoringPosition = false; }, 500);
        }
        
        // Attach Scroll Listener
        container.addEventListener('scroll', handleScroll);
        // Trigger once to capture short documents
        setTimeout(handleScroll, 100);
        
    } catch (e) {
        console.error('Error loading PDF:', e);
        loadingToast.textContent = "Failed to load PDF.";
        loadingToast.style.background = "#ef4444";
    }
}

function handleScroll() {
    if (isRestoringPosition) return;

    const scrollTop = container.scrollTop;
    const scrollHeight = container.scrollHeight;
    const clientHeight = container.clientHeight;
    
    // Calculate percentage based on scrolled amount
    let percentage = 0;
    if (scrollHeight <= clientHeight + 10) {
        percentage = 100;
    } else {
        // Accuracy threshold: If within 20px of bottom, it's 100%
        const isAtBottom = (scrollTop + clientHeight) >= (scrollHeight - 20);
        if (isAtBottom) {
            percentage = 100;
        } else {
            percentage = (scrollTop / (scrollHeight - clientHeight)) * 100;
        }
    }
    
    // Ensure it doesn't go backwards
    if (percentage > currentProgress) {
        const wasNotFinished = currentProgress < 100;
        currentProgress = Math.min(percentage, 100);
        updateProgressUI(currentProgress);
        
        // If just hit 100%, save IMMEDIATELY
        if (currentProgress === 100 && wasNotFinished) {
            clearTimeout(saveTimer);
            saveProgressToDB(100, scrollTop);
            return;
        }

        // Debounce DB save for incremental progress
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            saveProgressToDB(currentProgress, scrollTop);
        }, 1000);
    } else {
        // Still save scroll position periodically even if progress % doesn't increase
         clearTimeout(saveTimer);
         saveTimer = setTimeout(() => {
             saveProgressToDB(currentProgress, scrollTop);
         }, 1000);
    }
}

function updateProgressUI(percentage) {
    const p = Math.round(percentage);
    progressBar.style.width = `${p}%`;
    progressText.textContent = `${p}%`;
}

function saveProgressToDB(percentage, scrollTop) {
    const userRole = localStorage.getItem("userRole");
    if (userRole === "teacher") return Promise.resolve(); // Teachers don't track progress
    
    const formData = new FormData();
    formData.append('action', 'update_progress');
    formData.append('document_id', docId);
    formData.append('username', username);
    formData.append('progress_percentage', Math.min(percentage, 100));
    formData.append('last_position', Math.round(scrollTop));

    // Optimistic UI: Save to local storage for instant feedback on the subject page
    try {
        const localProg = JSON.parse(localStorage.getItem('localProgress') || '{}');
        localProg[docId] = Math.max(localProg[docId] || 0, Math.round(percentage));
        localStorage.setItem('localProgress', JSON.stringify(localProg));
    } catch(e) {}

    // Use keepalive to ensure the request finishes even if page closes
    return fetch('api/student.php', {
        method: 'POST',
        body: formData,
        keepalive: true
    })
    .then(r => r.json())
    .catch(err => console.error('Save error', err, { percentage, scrollTop }));
}

// Auto-save when user leaves the tab
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        saveProgressToDB(currentProgress, container.scrollTop);
    }
});
