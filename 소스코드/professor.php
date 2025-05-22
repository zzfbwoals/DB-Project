<?php
session_start();

if (!isset($_SESSION["userID"]) || $_SESSION["userRole"] !== 'professor') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "dbproject_user", "Gkrrytlfj@@33", "dbproject");
if ($conn->connect_error) die("DB 연결 실패: " . $conn->connect_error);

// 로그인한 유저 정보 조회
$professorID = $_SESSION["userID"];
$professorQuery = "SELECT userName FROM User WHERE userID = ?";
$stmt = $conn->prepare($professorQuery);
$stmt->bind_param("s", $professorID);
$stmt->execute();
$professorResult = $stmt->get_result();
$professorInfo = $professorResult->fetch_assoc();
$stmt->close();

// 승인/거절 처리 로직
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["approve"])) {
        $userID = $_POST["userID"];
        $courseID = $_POST["courseID"];
        
        // ExtraEnroll 상태 업데이트
        $stmt = $conn->prepare("UPDATE ExtraEnroll SET extraEnrollStatus = '승인' WHERE userID = ? AND courseID = ?");
        $stmt->bind_param("ss", $userID, $courseID);
        $stmt->execute();
        
        // Enroll 테이블에 추가 (중복 체크)
        $checkEnroll = $conn->prepare("SELECT COUNT(*) FROM Enroll WHERE userID = ? AND courseID = ?");
        $checkEnroll->bind_param("ss", $userID, $courseID);
        $checkEnroll->execute();
        $checkEnroll->bind_result($count);
        $checkEnroll->fetch();
        $checkEnroll->close();
        
        if ($count == 0) {
            $insertEnroll = $conn->prepare("INSERT INTO Enroll (userID, courseID) VALUES (?, ?)");
            $insertEnroll->bind_param("ss", $userID, $courseID);
            $insertEnroll->execute();
            $insertEnroll->close();
        }
        
        // Course의 currentEnrollment 1 증가
        $updateCourse = $conn->prepare("UPDATE Course SET currentEnrollment = currentEnrollment + 1 WHERE courseID = ?");
        $updateCourse->bind_param("s", $courseID);
        $updateCourse->execute();
        $updateCourse->close();
        
        $stmt->close();
    } elseif (isset($_POST["reject"])) {
        $stmt = $conn->prepare("UPDATE ExtraEnroll SET extraEnrollStatus = '거절' WHERE userID = ? AND courseID = ?");
        $stmt->bind_param("ss", $_POST["userID"], $_POST["courseID"]);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST["bulk_approve"]) || isset($_POST["bulk_reject"])) {
        if (isset($_POST["selected_items"])) {
            $selectedItems = $_POST["selected_items"];
            if (!empty($selectedItems)) {
                $status = isset($_POST["bulk_approve"]) ? '승인' : '거절';
                $placeholders = implode(",", array_fill(0, count($selectedItems), "(?, ?)"));
                $stmt = $conn->prepare("UPDATE ExtraEnroll SET extraEnrollStatus = ? WHERE (userID, courseID) IN ($placeholders)");
                $types = "s" . str_repeat("ss", count($selectedItems));
                $params = [$status];
                foreach ($selectedItems as $item) {
                    list($userID, $courseID) = explode("_", $item);
                    $params[] = $userID;
                    $params[] = $courseID;
                    if ($status === '승인') {
                        $checkEnroll = $conn->prepare("SELECT COUNT(*) FROM Enroll WHERE userID = ? AND courseID = ?");
                        $checkEnroll->bind_param("ss", $userID, $courseID);
                        $checkEnroll->execute();
                        $checkEnroll->bind_result($count);
                        $checkEnroll->fetch();
                        $checkEnroll->close();
                        if ($count == 0) {
                            $insertEnroll = $conn->prepare("INSERT INTO Enroll (userID, courseID) VALUES (?, ?)");
                            $insertEnroll->bind_param("ss", $userID, $courseID);
                            $insertEnroll->execute();
                            $insertEnroll->close();
                        }
                        // Course의 currentEnrollment 1 증가
                        $updateCourse = $conn->prepare("UPDATE Course SET currentEnrollment = currentEnrollment + 1 WHERE courseID = ?");
                        $updateCourse->bind_param("s", $courseID);
                        $updateCourse->execute();
                        $updateCourse->close();
                    }
                }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            }
        }
        header("Location: professor.php");
        exit();
    }
}

// 해당 교수의 과목에 대한 빌넣요청만 조회
$sql = "SELECT e.userID, e.courseID, e.reason, e.extraEnrollStatus, u.userName, c.courseName 
        FROM ExtraEnroll e
        JOIN User u ON e.userID = u.userID
        JOIN Course c ON e.courseID = c.courseID
        WHERE e.extraEnrollStatus = '대기' AND c.professorID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $professorID);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>순천향대학교 수강신청 시스템 - 교수</title>
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

        .logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo img {
            width: 200px;
        }

        h2 {
            font-size: 22px;
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: white;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: center;
        }

        th {
            background-color: #f2f2f2;
            color: #333;
            font-weight: bold;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background-color: #f0f7ff;
        }

        button {
            padding: 8px 15px;
            margin: 2px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        button[name="approve"] {
            background-color: #00a8ff;
            color: white;
        }

        button[name="approve"]:hover {
            background-color: #0090dd;
        }

        button[name="reject"] {
            background-color: #ff6b6b;
            color: white;
        }

        button[name="reject"]:hover {
            background-color: #ff5252;
        }

        .bulk-buttons {
            margin-top: 10px;
            text-align: center;
        }

        .bulk-buttons button {
            padding: 8px 20px;
            margin: 0 5px;
        }

        .bulk-approve {
            background-color: #00a8ff;
            color: white;
        }

        .bulk-approve:hover {
            background-color: #0090dd;
        }

        .bulk-reject {
            background-color: #ff6b6b;
            color: white;
        }

        .bulk-reject:hover {
            background-color: #ff5252;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .professorInfo {
            font-size: 14px;
            color: #666;
        }

        .logoutButton {
            padding: 8px 15px;
            background-color: #f2f2f2;
            color: #333;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .logoutButton:hover {
            background-color: #e0e0e0;
        }

        .noUser {
            text-align: center;
            padding: 30px;
            color: #888;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="section">
        <div class="logo">
            <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
        </div>

        <div class="header">
            <div class="professorInfo">
                <strong><?= htmlspecialchars($professorInfo['userName']) ?></strong> 교수님 환영합니다
            </div>
            <a href="login.php" class="logoutButton">로그아웃</a>
        </div>

        <h2>빌넣요청 관리</h2>

        <?php if ($result->num_rows > 0) { ?>
            <form method="post" id="bulkForm">
                <table>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>학번</th>
                        <th>이름</th>
                        <th>과목코드</th>
                        <th>과목명</th>
                        <th>사유</th>
                        <th>상태</th>
                        <th>관리</th>
                    </tr>

                    <?php while ($row = $result->fetch_assoc()) { ?>
                    <tr>
                        <td><input type="checkbox" name="selected_items[]" value="<?= htmlspecialchars($row["userID"]) . "_" . htmlspecialchars($row["courseID"]) ?>"></td>
                        <td><?= htmlspecialchars($row["userID"]) ?></td>
                        <td><?= htmlspecialchars($row["userName"]) ?></td>
                        <td><?= htmlspecialchars($row["courseID"]) ?></td>
                        <td><?= htmlspecialchars($row["courseName"]) ?></td>
                        <td><?= htmlspecialchars($row["reason"]) ?></td>
                        <td><?= htmlspecialchars($row["extraEnrollStatus"]) ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="userID" value="<?= htmlspecialchars($row["userID"]) ?>">
                                <input type="hidden" name="courseID" value="<?= htmlspecialchars($row["courseID"]) ?>">
                                <button type="submit" name="approve">승인</button>
                                <button type="submit" name="reject">거절</button>
                            </form>
                        </td>
                    </tr>
                    <?php } ?>
                </table>
                <div class="bulk-buttons">
                    <button type="submit" name="bulk_approve" class="bulk-approve" onclick="return confirm('선택한 항목을 일괄 승인하시겠습니까?');">일괄 승인</button>
                    <button type="submit" name="bulk_reject" class="bulk-reject" onclick="return confirm('선택한 항목을 일괄 거절하시겠습니까?');">일괄 거절</button>
                </div>
            </form>
        <?php } else { ?>
            <div class="noUser">
                대기 중인 빌넣요청이 없습니다.
            </div>
        <?php } ?>
    </div>

    <script>
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="selected_items[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>