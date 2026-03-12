document.getElementById("loginForm").addEventListener("submit", async function(e) {
    e.preventDefault();

    const username = document.getElementById("username").value.trim();
    const password = document.getElementById("password").value.trim();
    const errorEl = document.getElementById("error-message");
    const btn = document.getElementById("loginBtn");

    btn.textContent = "Signing in…";
    btn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'login');
    formData.append('username', username);
    formData.append('password', password);

    try {
        const response = await fetch('api/auth.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.status === 'success') {
            localStorage.setItem("studentName", data.user.name);
            localStorage.setItem("username", data.user.username);
            localStorage.setItem("userRole", data.user.role);
            
            setTimeout(() => {
                if (data.user.role === 'teacher') {
                    window.location.href = "dashboard_teacher.html";
                } else {
                    window.location.href = "dashboard.html";
                }
            }, 600);
        } else {
            btn.textContent = "Sign In \u2192";
            btn.disabled = false;
            Swal.fire({
                icon: 'error',
                title: 'Login Failed',
                text: data.message || "Invalid username or password"
            });
        }
    } catch (err) {
        console.error("Login Error:", err);
        btn.textContent = "Sign In \u2192";
        btn.disabled = false;
        Swal.fire({
            icon: 'error',
            title: 'Server Error',
            text: "Please try again later."
        });
    }
});