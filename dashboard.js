document.getElementById("startExamButton").addEventListener("click", function() {
    const selectedSubjects = [];
    
    // Get all checked subjects from checkboxes
    const checkboxes = document.querySelectorAll('.form-check-input:checked');
    checkboxes.forEach(checkbox => selectedSubjects.push(checkbox.value));
  
    // Check if exactly 3 subjects are selected
    if (selectedSubjects.length !== 3) {
      Swal.fire("Selection Required", "Please select exactly 3 subjects.", "info");
    } else {
      // Save selected subjects to localStorage
      localStorage.setItem("selectedSubjects", JSON.stringify(selectedSubjects));
      
      // Redirect to the exam page
      window.location.href = "exam.html";
    }
  });
  