<?php
session_start();

$conn = new mysqli("localhost", "dbproject_user", "Gkrrytlfj@@33", "dbproject");
if ($conn->connect_error) {
    die("DB 연결 실패: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $userID = $_POST['userID'];
    $userPassword = $_POST['userPassword'];

    // 프로시저 호출
    $stmt = $conn->prepare("CALL loginUser(?, ?, @success)");
    $stmt->bind_param("ss", $userID, $userPassword);
    $stmt->execute();
    $stmt->close();

    // 결과 가져오기
    $result = $conn->query("SELECT @success AS success");
    $row = $result->fetch_assoc();

    if ($row['success'] == 1) {
        // 로그인 성공 후 역할(userRole) 가져오기
        $stmt2 = $conn->prepare("SELECT userRole FROM User WHERE userID = ?");
        $stmt2->bind_param("s", $userID);
        $stmt2->execute();
        $res = $stmt2->get_result();
        $user = $res->fetch_assoc();
        $_SESSION['userID'] = $userID;
        $_SESSION['userRole'] = $user['userRole'];

        if ($user['userRole'] === 'student') { // 학생인 경우
            header("Location: mainStu.php");
        } else if ($user['userRole'] === 'professor') { // 교수인 경우
            header("Location: mainPro.php");
        } else { // 학생도 교수도 아닌 경우
            echo "<script>alert('알 수 없는 사용자 유형입니다.'); history.back();</script>";
        }
    } else {
        echo "<script>alert('학번 또는 비밀번호가 틀렸습니다.'); history.back();</script>";
    }
}
?>


<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>순천향대학교 수강신청 시스템</title>
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

        .loginForm input {
            width: 100%;
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .loginButton {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            background-color: #00a8ff;
            color: white;
            cursor: pointer;
            margin-top: 10px;
        }

        .loginButton:hover {
            background-color: #0090dd;
        }

        .linkToSign {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: #00a8ff;
            text-decoration: none;
            font-size: 14px;
        }

        .linkToSign:hover {
            text-decoration: underline;
        }

        .eventSection {
            margin-top: 40px;
        }

        .eventCard {
            background-color: #f9f9f9;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .eventCard .tag {
            display: inline-block;
            padding: 3px 8px;
            background-color: #007bff;
            color: white;
            font-size: 12px;
            border-radius: 3px;
            margin-right: 5px;
        }

        .eventCard .tag.new {
            background-color: #28a745;
        }

        .eventCard h4 {
            font-size: 14px;
            margin: 10px 0;
            color: #333;
        }

        .eventCard .date, .eventCard .time {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>

    <div class="section">
        <div class="loginSection">
            <div class="logo">
                <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
            </div>

            <div class="loginForm">
                <form action="" method="POST">
                    <input type="text" name="userID" placeholder="학번을 입력하세요" required>
                    <input type="password" name="userPassword" placeholder="비밀번호를 입력하세요" required>
                    <button type="submit" class="loginButton">로그인</button>
                </form>
                <a href="signup.html" class="linkToSign">아직 계정이 없으신가요? 회원가입</a>
            </div>
        </div>

        <div class="eventSection">

            <div class="eventCard">
                <span class="tag">재학생</span>
                <span class="tag new">신청</span>
                <h4>2025학년도 1학기 일반전공(4,5학기) 과목 기간</h4>
                <p class="date">날짜 : 2025.02.10(월) ~ 2025.02.14(금)</p>
                <p class="time">시간 : 10:00:00 ~ 15:59:59</p>
            </div>

            <div class="eventCard">
                <span class="tag">재학</span>
                <span class="tag new">전체</span>
                <h4>2025학년도 1학기 수강신청(4,5학기) 기간</h4>
                <p class="date">날짜 : 2025.02.17(월)</p>
                <p class="time">시간 : 10:00:00 ~ 23:59:59</p>
            </div>

            <div class="eventCard">
                <span class="tag">재학</span>
                <span class="tag new">전체</span>
                <h4>2025학년도 1학기 수강신청(학부, 신입생 제외) 기간</h4>
                <p class="date">날짜 : 2025.02.18(화)</p>
                <p class="time">시간 : 10:00:00 ~ 23:59:59</p>
            </div>

            <div class="eventCard">
                <span class="tag">재학</span>
                <span class="tag new">전체</span>
                <h4>2025학년도 1학기 수강신청(신편입) 기간</h4>
                <p class="date">날짜 : 2025.02.19(수)</p>
            </div>
        </div>
    </div>

</body>
</html>
