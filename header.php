<?php
// header.php - Appears on every page with admin/upload/quiz links.
?>
<style>
  .settings-menu {
    position: fixed;
    top: 10px;
    right: 10px;
    font-family: Arial, sans-serif;
  }

  .settings-icon {
    font-size: 26px;
    cursor: pointer;
    user-select: none;
  }

  .settings-dropdown {
    display: none;
    position: absolute;
    right: 0;
    margin-top: 5px;
    background: white;
    border: 1px solid #aaa;
    border-radius: 6px;
    padding: 8px 12px;
    box-shadow: 0px 2px 8px rgba(0,0,0,0.25);
    z-index: 9999;
  }

  .settings-dropdown a {
    display: block;
    padding: 6px 0;
    text-decoration: none;
    color: #000;
    font-size: 14px;
  }

  .settings-dropdown a:hover {
    background: #eee;
  }
</style>

<div class="settings-menu">
  <span class="settings-icon" onclick="toggleSettingsMenu()">⚙️</span>
  <div class="settings-dropdown" id="settings-menu-dropdown">
    <a href="quiz.php">Start Quiz</a>
    <a href="upload.php">Upload Static Questions</a>
    <a href="upload_dynamic.php">Upload Dynamic Questions</a>
    <a href="manage_questions.php">Manage Questions</a>
  </div>
</div>

<script>
  function toggleSettingsMenu() {
    const menu = document.getElementById('settings-menu-dropdown');
    menu.style.display = menu.style.display === "block" ? "none" : "block";
  }

  document.addEventListener('click', function(event) {
    const menu = document.getElementById('settings-menu-dropdown');
    const icon = document.querySelector('.settings-icon');
    if (!menu.contains(event.target) && !icon.contains(event.target)) {
      menu.style.display = "none";
    }
  });
</script>
