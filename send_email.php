<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Collect form data
    $name = htmlspecialchars(trim($_POST["name"]));
    $email = htmlspecialchars(trim($_POST["email"]));
    $subject = htmlspecialchars(trim($_POST["subject"]));
    $message = htmlspecialchars(trim($_POST["message"]));

    // Set the recipient email address
    $to = "dheerajkharwar102@gmail.com";  // Your email address
    $headers = "From: " . $email . "\r\n";
    $headers .= "Reply-To: " . $email . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    // Construct the email content
    $email_subject = "Contact Form Submission: " . $subject;
    $email_body = "<h2>Contact Form Submission</h2>
                    <p><strong>Name:</strong> $name</p>
                    <p><strong>Email:</strong> $email</p>
                    <p><strong>Subject:</strong> $subject</p>
                    <p><strong>Message:</strong><br/>$message</p>";

    // Send the email
    if (mail($to, $email_subject, $email_body, $headers)) {
        // Redirect to the same page with a success message
        header("Location: " . $_SERVER['HTTP_REFERER'] . "?status=success");
        exit();
    } else {
        // Redirect to the same page with an error message
        header("Location: " . $_SERVER['HTTP_REFERER'] . "?status=error");
        exit();
    }
}
?>
