# BTVTED System (Beta)

> [!IMPORTANT]
> This system is currently **UNDER PRODUCTION**. Core features for Reviewer Management and Reading Progress are implemented. The next phase focuses on enhancing Exam Logic, system-wide responsiveness, and robust error handling.

## 🎓 Overview
The **BTVTED System** is a comprehensive review and examination portal designed specifically for BTVTED students preparing for their board/non-board examinations. It provides a structured environment for teachers to manage materials and for students to track their preparation progress across multiple subject areas.

## 🛠️ Tech Stack
- **Frontend**: Vanilla HTML5, CSS3 (Modern Flexbox/Grid), Vanilla JavaScript (ES6+).
- **Backend**: Native PHP (7.4+).
- **Database**: MySQL.
- **Libraries**:
  - `PDF.js`: For high-fidelity browser-based PDF rendering.
  - `SweetAlert2`: For polished, interactive user notifications.

## 🔄 System Flowchart

```mermaid
graph TD
    Start((Start)) --> Login[Login Page]
    Login --> Role{Role Check}
    
    %% Teacher Path
    Role -- Teacher --> TDash[Teacher Dashboard]
    TDash --> RevMan[Reviewer Management]
    RevMan --> Upload[Upload PDFs/Docs]
    Upload --> ManualSub[Manual Subject Entry]
    TDash --> ExamMan[Exam Management]
    ExamMan --> AutoGen[Auto-Generate Questions]
    TDash --> StudRank[Student Rankings]
    StudRank --> RankFilter[Filter by Category/Subject]
    
    %% Student Path
    Role -- Student --> SDash[Student Dashboard]
    SDash --> NotiProf[Notifications & Profile]
    SDash --> SubCat[Select Subject Category]
    SubCat -- Prof/Gen Ed/Major --> SubList[Subject Materials List]
    SubList --> Reader[PDF Viewer]
    Reader -- Real-time --> ProgSync((Progress Auto-Save))
    SubList --> TakeExam[Take Pre-Board Exam]
    TakeExam --> ScoreSync[Score Recorded]
    ScoreSync --> TDash
```

## 🚀 How it Works

### 👨‍🏫 For Teachers
1.  **Reviewer Management**: Upload study materials (PDF, DOCX, etc.) Categorized into General Ed, Major, or Prof Ed.
2.  **Subject Management**: Create new specific subjects on the fly during upload if they don't exist.
3.  **Student Monitoring**: View real-time rankings and scores of students based on their performance in practice exams.

### 👨‍🎓 For Students
1.  **Structured Learning**: Browse materials organized by major board exam categories.
2.  **Smart Reading Progress**: The system tracks exactly how much of a document you've read. If you close a file at 62%, it stays at 62% and saves your exact scroll position for when you return.
3.  **Auto-Generated Exams**: Practice with quizzes generated from the uploaded reviewers to test your knowledge.
4.  **Profile & Notifications**: Track your latest scores and stay updated with teacher announcements.

---

## 📅 Roadmap (Next Phases)
- [ ] **Advanced Exam Logic**: More specialized question types and duration controls.
- [ ] **Full Responsiveness**: Mobile-first UI optimization for studying on the go.
- [ ] **Global Error Handling**: Centralized logging and user-friendly error recovery.
- [ ] **Performance Audit**: Optimizing large file loads and database queries.
code will be push later
