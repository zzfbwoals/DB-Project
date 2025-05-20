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

    // 학번 중복 여부 확인
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM User WHERE userID = ?");
    $checkStmt->bind_param("s", $userID);
    $checkStmt->execute();
    $checkStmt->bind_result($count);
    $checkStmt->fetch();
    $checkStmt->close();

    if ($count > 0) {
        echo "<script>alert('이미 존재하는 학번입니다. 다른 학번을 사용해주세요.'); history.back();</script>";
        $conn->close();
        exit;
    }

    // 중복 없을 경우 회원가입 진행
    $stmt = $conn->prepare("INSERT INTO User (userID, userPassword, userName, adminApproval, departmentID, grade, lastSemesterCredits, userRole)
                            VALUES (?, ?, ?, '대기', ?, ?, ?, ?)");
    $stmt->bind_param("ssssiids", $userID, $userPassword, $userName, $departmentID, $grade, $lastSemesterCredits, $userRole);

    if ($stmt->execute()) {
        echo "<script>alert('회원가입이 완료되었습니다. 이메일 인증 및 관리자 승인 후 로그인 가능.'); location.href='login.php';</script>";
    } else {
        echo "<script>alert('회원가입 실패: " . $stmt->error . "'); history.back();</script>";
    }

    $stmt->close();
}
$conn->close();
?>
