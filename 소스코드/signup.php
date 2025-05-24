<?php
// ---------------------------------------
// 초기 설정
// ---------------------------------------

$conn = new mysqli("localhost", "auth_user", "AuthPass123!", "dbproject");
if ($conn->connect_error)
{
    die("DB 연결 실패: " . $conn->connect_error);
}

// ---------------------------------------
// 데이터 조회
// ---------------------------------------

// 학과 목록 조회 (단과대학별 그룹화)
$departmentQuery = "SELECT d.departmentID, d.departmentName, c.collegeID, c.collegeName 
                    FROM Department d 
                    JOIN College c ON d.collegeID = c.collegeID 
                    ORDER BY c.collegeName, d.departmentName";
$departmentResult = $conn->query($departmentQuery);
$departmentsByCollege = [];
if ($departmentResult)
{
    while ($row = $departmentResult->fetch_assoc())
    {
        $departmentsByCollege[$row['collegeName']][] = [
            'departmentID' => $row['departmentID'],
            'departmentName' => $row['departmentName']
        ];
    }
    $departmentResult->free();
}

// ---------------------------------------
// 회원가입 처리
// ---------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST")
{
    // 입력 데이터 수집
    $userID = $_POST['userID'];
    $userPassword = password_hash($_POST['userPassword'], PASSWORD_DEFAULT);
    $userName = $_POST['userName'];
    $departmentID = $_POST['departmentID'];
    $userRole = $_POST['userRole'];

    // 교수일 경우 학번, 학년, 이수학점은 NULL로 설정
    if ($userRole === 'professor')
    {
        $grade = NULL;
        $lastSemesterCredits = NULL;
    }
    else
    {
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

    if ($userIdCount > 0)
    {
        echo "<script>alert('이미 존재하는 학번입니다.'); history.back();</script>";
        exit();
    }

    // 사용자 정보 삽입
    $stmt = $conn->prepare("INSERT INTO User (
        userID, userPassword, userName, adminApproval,
        departmentID, grade, lastSemesterCredits, userRole
    ) VALUES (?, ?, ?, '대기', ?, ?, ?, ?)");
    $stmt->bind_param("sssiids", $userID, $userPassword, $userName, $departmentID, $grade, $lastSemesterCredits, $userRole);

    // 삽입 실행 및 결과 처리
    if ($stmt->execute())
    {
        echo "<script>alert('회원가입이 완료되었습니다. 관리자 승인 후 로그인 가능합니다.'); location.href='login.php';</script>";
    }
    else
    {
        echo "<script>alert('회원가입 실패: " . addslashes($stmt->error) . "'); history.back();</script>";
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
    <title>순천향대학교 수강신청 시스템 - 회원가입</title>
    <style>
        *
        {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Malgun Gothic', sans-serif;
        }

        body
        {
            background-color: #f5f5f5;
        }

        .section
        {
            width: 400px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 50px;
        }

        .logo
        {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo img
        {
            width: 200px;
        }

        h3
        {
            font-size: 22px;
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }

        .signupForm input, .signupForm select
        {
            width: 100%;
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .signupButton
        {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            background-color: #00a8ff;
            color: white;
            cursor: pointer;
        }

        .signupButton:hover
        {
            background-color: #0090dd;
        }

        .backLink
        {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: #00a8ff;
            text-decoration: none;
            font-size: 14px;
        }

        .backLink:hover
        {
            text-decoration: underline;
        }

        .studentOnly
        {
            display: none;
        }
    </style>
</head>
<body>
    <!-- 회원가입 폼 렌더링 -->
    <div class="section">
        <div class="logo">
            <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
        </div>

        <h3>회원가입</h3>

        <form class="signupForm" action="signup.php" method="POST">
            <input type="text" name="userID" placeholder="학번" required>
            <input type="text" name="userName" placeholder="이름" required>
            <input type="password" name="userPassword" placeholder="비밀번호" required>

            <select name="departmentID" required>
                <option value="">학과를 선택하세요</option>
                <?php
                foreach ($departmentsByCollege as $collegeName => $departments)
                {
                ?>
                    <optgroup label="<?= htmlspecialchars($collegeName) ?>">
                        <?php
                        foreach ($departments as $dept)
                        {
                        ?>
                            <option value="<?= $dept['departmentID'] ?>"><?= htmlspecialchars($dept['departmentName']) ?></option>
                        <?php
                        }
                        ?>
                    </optgroup>
                <?php
                }
                ?>
            </select>

            <select name="userRole" id="roleSelect" required onchange="toggleStudentFields()">
                <option value="">역할을 선택하세요</option>
                <option value="student">학생</option>
                <option value="professor">교수</option>
            </select>

            <div id="studentfields" class="studentOnly">
                <input type="number" name="grade" placeholder="학년 (숫자)" min="1" max="5">
                <input type="number" name="lastSemesterCredits" step="0.01" placeholder="전학기 학점 (예: 4.3)" min="0" max="4.5">
            </div>

            <button type="submit" class="signupButton">회원가입 완료</button>
        </form>

        <a href="login.php" class="backLink">이미 계정이 있나요? 로그인</a>
    </div>

    <script>
        // 학생 필드 표시/숨김 토글
        function toggleStudentFields()
        {
            const role = document.getElementById('roleSelect').value;
            const studentFields = document.getElementById('studentfields');
            if (role === 'student')
            {
                studentFields.style.display = 'block';
            }
            else
            {
                studentFields.style.display = 'none';
            }
        }
    </script>
</body>
</html>