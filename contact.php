<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contact - School Management System</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; }
        nav { text-align: center; margin-bottom: 20px; }
        nav a { margin: 0 10px; color: #007bff; text-decoration: none; }
        nav a:hover { text-decoration: underline; }
        p { line-height: 1.6; }
        form { display: flex; flex-direction: column; }
        input, textarea { margin: 10px 0; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 10px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Contact Us</h1>
        <nav>
            <a href="index.php">Home</a>
            <a href="aboutus.php">About Us</a>
            <a href="contact.php">Contact</a>
            <a href="login.php">Login</a>
        </nav>
        <p>For inquiries, please contact us at:</p>
        <p>Email: info@schoolmanagement.com</p>
        <p>Phone: +1 (123) 456-7890</p>
        <p>Address: 123 School Street, Education City</p>

        <?php
        session_start();
        require "admin/db.php";
        $msg = "";
        $errors = [];

        if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['user_id'])) {
            $username = trim($_POST["username"]);
            $email = trim($_POST["email"]);
            $message = trim($_POST["message"]);

            if (empty($username)) {
                $errors['username'] = "Username is required.";
            }
            if (empty($email)) {
                $errors['email'] = "Email is required.";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = "Invalid email format.";
            }
            if (empty($message)) {
                $errors['message'] = "Message is required.";
            }

            if (empty($errors)) {
                // Send to admin
                $admin_result = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
                if ($admin_result && $admin_result->num_rows > 0) {
                    $admin = $admin_result->fetch_assoc();
                    $to_user_id = $admin['id'];
                    $from_user_id = $_SESSION['user_id'];

                    $stmt = $conn->prepare("INSERT INTO messages(from_user_id, to_user_id, message) VALUES(?, ?, ?)");
                    if ($stmt) {
                        $full_message = "From: $username ($email)\n\n$message";
                        $stmt->bind_param("iis", $from_user_id, $to_user_id, $full_message);
                        $stmt->execute();

                        $msg = "Message sent successfully.";
                    } else {
                        $errors['general'] = "Could not prepare message insert: " . $conn->error;
                    }
                } else {
                    $errors['general'] = "No admin found.";
                }
            }
        }
        ?>

        <?php if (isset($_SESSION['user_id'])): ?>
            <h2>Send Message to Admin</h2>
            <?php if (!empty($msg)) echo "<p class='success'>$msg</p>"; ?>
            <?php if (!empty($errors['general'])) echo "<p class='error'>{$errors['general']}</p>"; ?>
            <form method="post">
                <input type="text" name="username" placeholder="Username" value="<?= htmlspecialchars($_SESSION['username'] ?? '') ?>" required>
                <?php if (!empty($errors['username'])) echo "<p class='error'>{$errors['username']}</p>"; ?>
                <input type="email" name="email" placeholder="Email" required>
                <?php if (!empty($errors['email'])) echo "<p class='error'>{$errors['email']}</p>"; ?>
                <textarea name="message" rows="5" placeholder="Your message" required></textarea>
                <?php if (!empty($errors['message'])) echo "<p class='error'>{$errors['message']}</p>"; ?>
                <button type="submit">Send Message</button>
            </form>
        <?php else: ?>
            <p>Please <a href="login.php">login</a> to send a message to admin.</p>
        <?php endif; ?>
    </div>
</body>
</html>