<?php
// ---------------------------------------
// 초기 설정
// ---------------------------------------

$conn = new mysqli("localhost", "auth_user", "AuthPass123!", "dbproject");
if ($conn->connect_error) {
    die("DB 연결 실패: " . $conn->connect_error);
}

// 세션 시작
session_start();
if (!isset($_SESSION['userID'])) {
    echo "<script>alert('로그인이 필요합니다.'); location.href='login.php';</script>";
    exit();
}

$userID = $_SESSION['userID'];

// ---------------------------------------
// 사용자 정보 조회
// ---------------------------------------

$stmt = $conn->prepare("SELECT u.userID, u.userName, u.userPassword, u.adminApproval, u.departmentID, 
                        u.grade, u.lastSemesterCredits, u.userRole, d.departmentName, c.collegeName 
                        FROM User u 
                        JOIN Department d ON u.departmentID = d.departmentID 
                        JOIN College c ON d.collegeID = c.collegeID 
                        WHERE u.userID = ?");
$stmt->bind_param("s", $userID);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "<script>alert('사용자 정보를 찾을 수 없습니다.'); location.href='login.php';</script>";
    exit();
}

// ---------------------------------------
// 회원 정보 수정 처리
// ---------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update'])) {
    // 비밀번호 변경 처리
    $newPassword = !empty($_POST['newPassword']) ? $_POST['newPassword'] : null;
    $confirmPassword = !empty($_POST['confirmPassword']) ? $_POST['confirmPassword'] : null;
    $userPassword = $user['userPassword']; // 기본값은 기존 비밀번호

    // 비밀번호 입력 여부 확인
    if (!$newPassword && !$confirmPassword) {
        echo "<script>alert('비밀번호를 입력해주세요.'); history.back();</script>";
        exit();
    }

    if ($newPassword && $confirmPassword) {
        if ($newPassword === $confirmPassword) {
            $userPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        } else {
            echo "<script>alert('새 비밀번호와 확인 비밀번호가 일치하지 않습니다.'); history.back();</script>";
            exit();
        }
    }

    // 사용자 정보 수정 (이름, 학과, 학년, 전학기 학점은 수정 불가)
    $stmt = $conn->prepare("UPDATE User SET userPassword = ? WHERE userID = ?");
    $stmt->bind_param("ss", $userPassword, $userID);

    // 수정 실행 및 결과 처리
    if ($stmt->execute()) {
        echo "<script>alert('비밀번호가 변경되었습니다.'); location.href='myPage.php';</script>";
    } else {
        echo "<script>alert('비밀번호 변경 실패: " . addslashes($stmt->error) . "'); history.back();</script>";
    }
    $stmt->close();
}

// ---------------------------------------
// 회원 탈퇴 처리
// ---------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete'])) {
    // 사용자 삭제
    $stmt = $conn->prepare("DELETE FROM User WHERE userID = ?");
    $stmt->bind_param("s", $userID);
    
    // 삭제 실행 및 결과 처리
    if ($stmt->execute()) {
        session_destroy();
        echo "<script>alert('회원 탈퇴가 완료되었습니다.'); location.href='login.php';</script>";
    } else {
        echo "<script>alert('회원 탈퇴 실패: " . addslashes($stmt->error) . "'); history.back();</script>";
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>순천향대학교 수강신청 시스템 - 마이페이지</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Malgun Gothic', sans-serif;
        }

        body {
            background-color: #f5f5f5;
        }

        .section {
            width: 400px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 50px;
        }

        .logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo img {
            width: 200px;
        }

        h3 {
            font-size: 22px;
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }

        .mypageForm input, .mypageForm select {
            width: 100%;
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .readonly-field {
            background-color: #f0f0f0;
            cursor: not-allowed;
        }

        .updateButton, .deleteButton {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            color: white;
            cursor: pointer;
            margin-bottom: 10px;
        }

        .updateButton {
            background-color: #00a8ff;
        }

        .updateButton:hover {
            background-color: #0090dd;
        }

        .deleteButton {
            background-color: #ff4d4d;
        }

        .deleteButton:hover {
            background-color: #e04343;
        }

        .backLink {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: #00a8ff;
            text-decoration: none;
            font-size: 14px;
        }

        .backLink:hover {
            text-decoration: underline;
        }

        .studentOnly {
            display: <?php echo $user['userRole'] === 'student' ? 'block' : 'none'; ?>;
        }
    </style>
</head>
<body>
    <!-- 회원 정보 수정 폼 렌더링 -->
    <div class="section">
        <div class="logo">
            <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
        </div>

        <h3>마이페이지</h3>

        <form class="mypageForm" action="myPage.php" method="POST">
            <input type="text" name="userID" value="<?php echo htmlspecialchars($user['userID']); ?>" 
                   class="readonly-field" readonly>
            <input type="text" name="userName" value="<?php echo htmlspecialchars($user['userName']); ?>" 
                   class="readonly-field" readonly>
            <input type="password" name="newPassword" placeholder="새 비밀번호 (변경 시 입력)">
            <input type="password" name="confirmPassword" placeholder="새 비밀번호 확인">
            <input type="text" name="departmentName" value="<?php echo htmlspecialchars($user['departmentName']); ?>" 
                   class="readonly-field" readonly>

            <div id="studentfields" class="studentOnly">
                <input type="number" name="grade" value="<?php echo htmlspecialchars($user['grade']); ?>" 
                       class="readonly-field" readonly>
                <input type="number" name="lastSemesterCredits" step="0.01" 
                       value="<?php echo htmlspecialchars($user['lastSemesterCredits']); ?>" 
                       class="readonly-field" readonly>
            </div>

            <button type="submit" name="update" class="updateButton">비밀번호 변경</button>
            <button type="submit" name="delete" class="deleteButton" 
                    onclick="return confirm('정말 탈퇴하시겠습니까?');">회원 탈퇴</button>
        </form>

        <a href="login.php" class="backLink">로그아웃</a>
    </div>
</body>
</html>