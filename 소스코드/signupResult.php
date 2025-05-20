<?php
$conn = new mysqli("localhost", "dbproject_user", "Gkrrytlfj@@33", "dbproject");
if ($conn->connect_error) {
    die("DB 연결 실패: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $userID = $_POST['userID'];
    $userPassword = password_hash($_POST['userPassword'], PASSWORD_DEFAULT); // 보안상 암호화
    $userName = $_POST['userName'];
    $userEmail = $_POST['userEmail'];
    $departmentID = $_POST['departmentID'];
    $userRole = $_POST['userRole'];

    // 교수일 경우 학년, 성적은 null
    if ($userRole === 'professor') {
        $grade = NULL;
        $lastSemesterCredits = NULL;
    } else {
        $grade = $_POST['grade'];
        $lastSemesterCredits = $_POST['lastSemesterCredits'];
    }

    $stmt = $conn->prepare("INSERT INTO User (userID, userPassword, userName, userEmail, emailVerified, emailVerificationCode, adminApproval, departmentID, grade, lastSemesterCredits, userRole) VALUES (?, ?, ?, ?, 0, NULL, '대기', ?, ?, ?, ?)");
    $stmt->bind_param("ssssiids", $userID, $userPassword, $userName, $userEmail, $departmentID, $grade, $lastSemesterCredits, $userRole);

    if ($stmt->execute()) {
        echo "<script>alert('회원가입이 완료되었습니다. 이메일 인증 및 관리자 승인 후 로그인 가능.'); location.href='login.php';</script>";
    } else {
        echo "<script>alert('회원가입 실패: " . $stmt->error . "'); history.back();</script>";
    }

    $stmt->close();
}
$conn->close();
?>
