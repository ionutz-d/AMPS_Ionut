document.addEventListener("DOMContentLoaded", () => {
    const savedTheme = localStorage.getItem("theme");

    if (savedTheme === "dark") {
        document.body.classList.add("dark");
        document.getElementById("themeIcon").textContent = "🌞";
    }
});

function toggleTheme() {
    document.body.classList.toggle("dark");

    if (document.body.classList.contains("dark")) {
        document.getElementById("themeIcon").textContent = "🌞";
        localStorage.setItem("theme", "dark");
    } else {
        document.getElementById("themeIcon").textContent = "🌙";
        localStorage.setItem("theme", "light");
    }
}


function toggleTheme() {
    document.body.classList.toggle("dark-theme");
}


document.addEventListener("DOMContentLoaded", () => {
    const body = document.body;
    const toggle = document.querySelector(".theme-toggle");

    // Load saved theme
    const saved = localStorage.getItem("amc_theme");
    if (saved === "dark") {
        body.classList.add("dark");
        document.getElementById("themeIcon").textContent = "☀️";
    }

    // Toggle
    toggle.addEventListener("click", () => {
        body.classList.toggle("dark");

        if (body.classList.contains("dark")) {
            localStorage.setItem("amc_theme", "dark");
            document.getElementById("themeIcon").textContent = "☀️";
        } else {
            localStorage.setItem("amc_theme", "light");
            document.getElementById("themeIcon").textContent = "🌙";
        }
    });
});
