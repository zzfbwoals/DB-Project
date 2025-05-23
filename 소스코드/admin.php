<?php
// ---------------------------------------
// 초기 설정
// ---------------------------------------

session_start();

// 관리자 권한 확인
if (!isset($_SESSION["userID"]) || $_SESSION["userRole"] !== 'admin')
{
    header("Location: login.php");
    exit();
}

// admin_user로 접속
$conn = new mysqli("localhost", "admin_user", "AdminPass123!", "dbproject");
if ($conn->connect_error)
{
    die("DB 연결 실패: " . $conn->connect_error);
}

// ---------------------------------------
// 데이터 조회
// ---------------------------------------

// 로그인한 관리자 정보 조회
$adminID = $_SESSION["userID"];
$adminQuery = "SELECT userName FROM User WHERE userID = ?";
$stmt = $conn->prepare($adminQuery);
$stmt->bind_param("s", $adminID);
$stmt->execute();
$adminResult = $stmt->get_result();
$adminInfo = $adminResult->fetch_assoc();
$stmt->close();

// 승인 대기 중인 사용자 조회
$sql = "SELECT userID, userName, userRole, grade FROM User WHERE adminApproval = '대기'";
$result = $conn->query($sql);

// ---------------------------------------
// 승인/거절 처리
// ---------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST")
{
    // 사용자 승인 처리
    if (isset($_POST["approve"]))
    {
        $stmt = $conn->prepare("UPDATE User SET adminApproval = '승인' WHERE userID = ?");
        $stmt->bind_param("s", $_POST["approve"]);
        $stmt->execute();
        $stmt->close();
    }
    // 사용자 거절 처리
    elseif (isset($_POST["reject"]))
    {
        $stmt = $conn->prepare("UPDATE User SET adminApproval = '거절' WHERE userID = ?");
        $stmt->bind_param("s", $_POST["reject"]);
        $stmt->execute();
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>순천향대학교 수강신청 시스템 - 관리자</title>
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
            width: 80%;
            max-width: 1000px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 50px;
            margin-bottom: 50px;
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

        h2
        {
            font-size: 22px;
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }

        table
        {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: white;
        }

        th, td
        {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: center;
        }

        th
        {
            background-color: #f2f2f2;
            color: #333;
            font-weight: bold;
        }

        tr:nth-child(even)
        {
            background-color: #f9f9f9;
        }

        tr:hover
        {
            background-color: #f0f7ff;
        }

        button
        {
            padding: 8px 15px;
            margin: 2px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        button[name="approve"]
        {
            background-color: #00a8ff;
            color: white;
        }

        button[name="approve"]:hover
        {
            background-color: #0090dd;
        }

        button[name="reject"]
        {
            background-color: #ff6b6b;
            color: white;
        }

        button[name="reject"]:hover
        {
            background-color: #ff5252;
        }

        .header
        {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .adminInfo
        {
            font-size: 14px;
            color: #666;
        }

        .logoutButton
        {
            padding: 8px 15px;
            background-color: #f2f2f2;
            color: #333;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .logoutButton:hover
        {
            background-color: #e0e0e0;
        }

        .noUser
        {
            text-align: center;
            padding: 30px;
            color: #888;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <!-- 사용자 승인 관리 UI 렌더링 -->
    <div class="section">
        <div class="logo">
            <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
        </div>

        <div class="header">
            <div class="adminInfo">
                <strong><?= htmlspecialchars($adminInfo['userName']) ?></strong> 관리자님 환영합니다
            </div>
            <a href="login.php" class="logoutButton">로그아웃</a>
        </div>

        <h2>사용자 승인 관리</h2>

        <?php
        if ($result->num_rows > 0)
        {
        ?>
            <table>
                <tr>
                    <th>학번</th>
                    <th>이름</th>
                    <th>역할</th>
                    <th>학년</th>
                    <th>회원가입 승인/거절</th>
                </tr>

                <?php
                while ($row = $result->fetch_assoc())
                {
                ?>
                    <tr>
                        <td><?= htmlspecialchars($row["userID"]) ?></td>
                        <td><?= htmlspecialchars($row["userName"]) ?></td>
                        <td><?= htmlspecialchars($row["userRole"]) ?></td>
                        <td><?= htmlspecialchars($row["grade"] ?? "해당없음") ?></td>
                        <td>
                            <form method="post">
                                <button type="submit" name="approve" value="<?= htmlspecialchars($row["userID"]) ?>">승인</button>
                                <button type="submit" name="reject" value="<?= htmlspecialchars($row["userID"]) ?>">거절</button>
                            </form>
                        </td>
                    </tr>
                <?php
                }
                ?>
            </table>
        <?php
        }
        else
        {
        ?>
            <div class="noUser">
                승인 대기 중인 사용자가 없습니다.
            </div>
        <?php
        }
        ?>
    </div>
</body>
</html>
<?php
$conn->close();
?>