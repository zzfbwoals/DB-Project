<?php
session_start();

// DB 연결
$conn = new mysqli("localhost", "dbproject_user", "Gkrrytlfj@@33", "dbproject");
if ($conn->connect_error) die("DB 연결 실패: " . $conn->connect_error);

if ($_SERVER["REQUEST_METHOD"] === "POST") 
{
    $userID = $_POST['userID'];
    $userPassword = $_POST['userPassword'];

    // 사용자 정보 조회
    $stmt = $conn->prepare("SELECT userPassword, userRole, adminApproval FROM User WHERE userID = ?");
    $stmt->bind_param("s", $userID);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) 
    {
        // 비밀번호 해시 확인
        if (password_verify($userPassword, $row['userPassword'])) 
        {

            // 관리자 승인 확인
            if ($row['adminApproval'] === '대기') 
            {
                echo "<script>alert('관리자의 승인이 아직 완료되지 않았습니다.'); history.back();</script>";
                exit();
            }
            else if ($row['adminApproval'] === '거절') 
            {
                echo "<script>alert('관리자에 승인이 거절되었습니다.'); history.back();</script>";
                exit();
            }

            // 로그인 성공: 세션 설정 및 페이지 이동
            $_SESSION['userID'] = $userID;
            $_SESSION['userRole'] = $row['userRole'];

            if ($row['userRole'] === 'student') 
                header("Location: enroll.php");
            else if ($row['userRole'] === 'professor') 
                header("Location: professor.php");
            else if ($row['userRole'] === 'admin') 
                header("Location: admin.php");
            else 
            {
                echo "<script>alert('알 수 없는 사용자 유형입니다.'); history.back();</script>";
                exit();
            }
        } 
        else 
        {
            echo "<script>alert('비밀번호가 일치하지 않습니다.'); history.back();</script>";
            exit();
        }
    } 
    else 
    {
        echo "<script>alert('존재하지 않는 사용자입니다.'); history.back();</script>";
        exit();
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
    <title>순천향대학교 수강신청 시스템</title>
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

        .loginForm input 
        {
            width: 100%;
            padding: 12px;
            margin-bottom: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .loginButton 
        {
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

        .loginButton:hover 
        {
            background-color: #0090dd;
        }

        .linkToSign 
        {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: #00a8ff;
            text-decoration: none;
            font-size: 14px;
        }

        .linkToSign:hover 
        {
            text-decoration: underline;
        }

        .eventSection 
        {
            margin-top: 40px;
        }

        .eventCard 
        {
            background-color: #f9f9f9;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .eventCard .tag 
        {
            display: inline-block;
            padding: 3px 8px;
            background-color: #007bff;
            color: white;
            font-size: 12px;
            border-radius: 3px;
            margin-right: 5px;
        }

        .eventCard .tag.new 
        {
            background-color: #28a745;
        }

        .eventCard h4 
        {
            font-size: 14px;
            margin: 10px 0;
            color: #333;
        }

        .eventCard .date, .eventCard .time 
        {
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

            <h3>수강신청 로그인</h3>

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

            <h3>수강신청 일정</h3>

            <div class="eventCard">
                <span class="tag">과목확인</span>
                <span class="tag new">전체</span>
                <h4>2025학년도 1학기 예비수강신청 기간</h4>
                <p class="date">날짜 : 2025.02.17(월) ~ 2025.02.21(금)</p>
                <p class="time">시간 : 10:00 ~ 24:00 (2.21(금)은 16:00까지)</p>
            </div>

            <div class="eventCard">
                <span class="tag">1차</span>
                <span class="tag new">4학년</span>
                <h4>2025학년도 1학기 수강신청(4학년) 기간</h4>
                <p class="date">날짜 : 2025.02.24(월)</p>
                <p class="time">시간 : 10:00 ~ 24:00</p>
            </div>

            <div class="eventCard">
                <span class="tag">1차</span>
                <span class="tag new">3학년</span>
                <h4>2025학년도 1학기 수강신청(3학년) 기간</h4>
                <p class="date">날짜 : 2025.02.25(화)</p>
                <p class="time">시간 : 10:00 ~ 24:00</p>
            </div>

            <div class="eventCard">
                <span class="tag">1차</span>
                <span class="tag new">2학년</span>
                <h4>2025학년도 1학기 수강신청(2학년) 기간</h4>
                <p class="date">날짜 : 2025.02.26(수)</p>
                <p class="time">시간 : 10:00 ~ 24:00</p>
            </div>

            <div class="eventCard">
                <span class="tag">1차</span>
                <span class="tag new">1학년</span>
                <h4>2025학년도 1학기 수강신청(신편입) 기간</h4>
                <p class="date">날짜 : 2025.02.27(목)</p>
                <p class="time">시간 : 10:00 ~ 24:00</p>
            </div>

            <div class="eventCard">
                <span class="tag">1차</span>
                <span class="tag new">전체</span>
                <h4>2025학년도 1학기 수강신청(전체) 기간</h4>
                <p class="date">날짜 : 2025.02.28(금)</p>
                <p class="time">시간 : 10:00 ~ 24:00</p>
            </div>

                <div class="eventCard">
                <span class="tag">빌넣</span>
                <span class="tag new">전체</span>
                <h4>2025학년도 1학기 빌넣요청 기간</h4>
                <p class="date">날짜 : 2025.03.01(토) ~ 2025.03.02(일)</p>
                <p class="time">시간 : 10:00 ~ 24:00</p>
            </div>

                <div class="eventCard">
                <span class="tag">2차</span>
                <span class="tag new">전체</span>
                <h4>2025학년도 1학기 수강신청 정정 기간</h4>
                <p class="date">날짜 : 2025.03.03(월) ~ 2025.03.07(금)</p>
                <p class="time">시간 : 10:00 ~ 24:00</p>
            </div>
        </div>
    </div>

</body>
</html>
