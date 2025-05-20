<?php
session_start();

if (!isset($_SESSION["userID"]) || $_SESSION["userRole"] !== 'admin')
{
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "dbproject_user", "Gkrrytlfj@@33", "dbproject");
if ($conn->connect_error) die("DB 연결 실패: " . $conn->connect_error);

// 승인 처리 로직
if ($_SERVER["REQUEST_METHOD"] === "POST")
{
    if (isset($_POST["approve"]))
    {
        $stmt = $conn->prepare("UPDATE User SET adminApproval = '승인' WHERE userID = ?");
        $stmt->bind_param("s", $_POST["approve"]);
        $stmt->execute();
        $stmt->close();
    }
    elseif (isset($_POST["reject"]))
    {
        $stmt = $conn->prepare("UPDATE User SET adminApproval = '거절' WHERE userID = ?");
        $stmt->bind_param("s", $_POST["reject"]);
        $stmt->execute();
        $stmt->close();
    }
}

// 승인 대기 중인 사용자 조회
$sql = "SELECT userID, userName, userRole, grade FROM User WHERE adminApproval = '대기'";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>관리자 승인 페이지</title>
    <style>
        body
        {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            text-align: center;
        }
        table
        {
            margin: auto;
            margin-top: 30px;
            border-collapse: collapse;
            background-color: white;
            width: 80%;
        }
        th, td
        {
            border: 1px solid #ccc;
            padding: 10px;
        }
        th
        {
            background-color: #eee;
        }
        form
        {
            display: inline;
        }
        input[type="submit"]
        {
            padding: 5px 10px;
            margin: 2px;
            cursor: pointer;
        }
        h2
        {
            margin-top: 40px;
        }
    </style>
</head>
<body>
    <h2>관리자 승인 대기 목록</h2>

    <table>
        <tr>
            <th>아이디</th>
            <th>이름</th>
            <th>역할</th>
            <th>학년</th>
            <th>승인/거절</th>
        </tr>

        <?php while ($row = $result->fetch_assoc()) { ?>
        <tr>
            <td><?= htmlspecialchars($row["userID"]) ?></td>
            <td><?= htmlspecialchars($row["userName"]) ?></td>
            <td><?= htmlspecialchars($row["userRole"]) ?></td>
            <td><?= htmlspecialchars($row["grade"]) ?></td>
            <td>
                <form method="post">
                    <button type="submit" name="approve" value="<?= $row["userID"] ?>">승인</button>
                    <button type="submit" name="reject" value="<?= $row["userID"] ?>">거절</button>
                </form>
            </td>
        </tr>
        <?php } ?>
    </table>
</body>
</html>
