<?php
$conn = new mysqli("localhost", "dbproject_user", "Gkrrytlfj@@33", "dbproject");
if ($conn->connect_error) {
    die("DB 연결 실패: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $userID = $_POST['userID'];
    $userPassword = password_hash($_POST['userPassword'], PASSWORD_DEFAULT);
    $userName = $_POST['userName'];
    $departmentID = $_POST['departmentID'];
    $userRole = $_POST['userRole'];

    // 교수일 경우 학번, 학년, 이수학점은 NULL로 설정
    if ($userRole === 'professor') {
        $grade = NULL;
        $lastSemesterCredits = NULL;
    } else {
        $grade = $_POST['grade'];
        $lastSemesterCredits = $_POST['lastSemesterCredits'];
    }

    // 학번 중복 검사
    $stmt = $conn->prepare("SELECT COUNT(*) FROM User WHERE userID = ?");
    $stmt->bind_param("s", $userID);
    $stmt->execute();
    $stmt->bind_result($userIdCount);
    $stmt->fetch();
    $stmt->close();

    if ($userIdCount > 0) {
        echo "<script>alert('이미 존재하는 학번입니다.'); history.back();</script>";
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO User (
        userID, userPassword, userName, adminApproval,
        departmentID, grade, lastSemesterCredits, userRole
    ) VALUES (?, ?, ?, '대기', ?, ?, ?, ?)");

    $stmt->bind_param("sssiids", $userID, $userPassword, $userName, $departmentID, $grade, $lastSemesterCredits, $userRole);

    if ($stmt->execute()) {
        echo "<script>alert('회원가입이 완료되었습니다. 관리자 승인 후 로그인 가능합니다.'); location.href='login.php';</script>";
    } else {
        echo "<script>alert('회원가입 실패: " . $stmt->error . "'); history.back();</script>";
    }

    $stmt->close();
}
$conn->close();
?>
