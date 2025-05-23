<?php
header('Content-Type: text/html; charset=UTF-8'); // UTF-8 인코딩 설정

session_start();

// 사용자가 로그인되어 있고 학생인지 확인
if (!isset($_SESSION["userID"]) || $_SESSION["userRole"] !== 'student')
{
    // 로그인 페이지로 리다이렉트
    header("Location: login.php");
    exit();
}

// student_user로 접속
$conn = new mysqli("localhost", "student_user", "StudentPass123!", "dbproject");
if ($conn->connect_error)
{
    die("DB 연결 실패: " . $conn->connect_error);
}
$conn->set_charset("utf8");

// 학생 ID 정의
$studentID = $_SESSION["userID"];

// ---------------------------------------
// 데이터 조회
// ---------------------------------------

// 학생 정보 가져오기
$studentQuery = "SELECT u.userID, u.userName, u.grade, u.lastSemesterCredits, u.userRole, 
                        u.departmentID, d.departmentName, c.collegeName 
                FROM User u 
                LEFT JOIN Department d ON u.departmentID = d.departmentID
                LEFT JOIN College c ON d.collegeID = c.collegeID
                WHERE u.userID = ?";
$stmt = $conn->prepare($studentQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$studentResult = $stmt->get_result();
$studentInfo = $studentResult->fetch_assoc();
$stmt->close();

// Course 테이블에서 area 목록 가져오기
$areaQuery = "SELECT DISTINCT area FROM Course WHERE area IS NOT NULL AND area != '' ORDER BY area";
$areaResult = $conn->query($areaQuery);
$areas = [];
if ($areaResult)
{
    while ($row = $areaResult->fetch_assoc())
    {
        $areas[] = $row['area'];
    }
    $areaResult->free();
}
// (중핵) 하단으로 정렬
$areas_normal = [];
$areas_core = [];
foreach ($areas as $area_item)
{
    if (strpos($area_item, '(중핵)') !== false)
    {
        $areas_core[] = $area_item;
    }
    else
    {
        $areas_normal[] = $area_item;
    }
}
sort($areas_normal); // 일반 항목 가나다순 정렬
sort($areas_core);   // (중핵) 항목 가나다순 정렬
$areas = array_merge($areas_normal, $areas_core); // 두 배열을 합침

// 검색 드롭다운을 위한 모든 단과대학 조회
$collegesQuery = "SELECT * FROM College ORDER BY collegeName";
$colleges = $conn->query($collegesQuery);
$colleges_arr_for_js = []; // JS 전달용 배열
if ($colleges && $colleges->num_rows > 0)
{
    $colleges_arr_for_js = $colleges->fetch_all(MYSQLI_ASSOC);
    $colleges->data_seek(0); // HTML 생성을 위해 포인터 리셋
}

// 검색 드롭다운을 위한 모든 학과 조회
$departmentsQuery = "SELECT d.*, c.collegeName FROM Department d 
                     JOIN College c ON d.collegeID = c.collegeID 
                     ORDER BY c.collegeName, d.departmentName";
$departments = $conn->query($departmentsQuery);
$departments_arr_for_js = []; // JS 전달용 배열
if ($departments && $departments->num_rows > 0)
{
    $departments_arr_for_js = $departments->fetch_all(MYSQLI_ASSOC);
    $departments->data_seek(0); // HTML 생성을 위해 포인터 리셋
}

// 빌넣요청 데이터 가져오기 (검색 결과에서 요청 상태 확인용)
$extraEnrollQuery = "SELECT courseID, extraEnrollStatus FROM ExtraEnroll WHERE userID = ? AND extraEnrollStatus = '대기'";
$stmt = $conn->prepare($extraEnrollQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$extraEnrollResult = $stmt->get_result();
$extraEnrollCourses = [];
while ($row = $extraEnrollResult->fetch_assoc())
{
    $extraEnrollCourses[$row['courseID']] = $row['extraEnrollStatus'];
}
$stmt->close();

// ---------------------------------------
// 데이터 처리
// ---------------------------------------

// 최대 학점 계산
$maxCredits = ($studentInfo['lastSemesterCredits'] >= 3.0) ? 19 : 18;

// 현재 수강신청 학점 계산 (Enroll 테이블)
$currentCreditsQuery = "SELECT SUM(c.credits) as totalCredits 
                       FROM Enroll e 
                       JOIN Course c ON e.courseID = c.courseID 
                       WHERE e.userID = ?";
$stmt = $conn->prepare($currentCreditsQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$currentCreditsResult = $stmt->get_result();
$currentCredits = $currentCreditsResult->fetch_assoc()['totalCredits'] ?? 0;
$stmt->close();

// 현재 빌넣요청 대기 학점 계산 (ExtraEnroll 테이블)
$extraCreditsQuery = "SELECT SUM(c.credits) as totalExtraCredits 
                      FROM ExtraEnroll ee 
                      JOIN Course c ON ee.courseID = c.courseID 
                      WHERE ee.userID = ? AND ee.extraEnrollStatus = '대기'";
$stmt = $conn->prepare($extraCreditsQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$extraCreditsResult = $stmt->get_result();
$extraCredits = $extraCreditsResult->fetch_assoc()['totalExtraCredits'] ?? 0;
$stmt->close();

// ---------------------------------------
// 빌넣요청 처리 로직
// ---------------------------------------

// 빌넣요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'extraEnroll')
{
    $courseID = trim($_POST['courseID']);
    $reason = trim($_POST['reason']);

    // 트랜잭션 시작 - 데이터 일관성 보장
    $conn->begin_transaction();
    try
    {
        // 1. 과목코드 유효성 확인 및 정원 확인
        $courseCheckQuery = "SELECT courseID, capacity, currentEnrollment, credits, courseName FROM Course WHERE courseID = ?";
        $stmt = $conn->prepare($courseCheckQuery);
        $stmt->bind_param("s", $courseID);
        $stmt->execute();
        $courseResult = $stmt->get_result();

        if ($courseResult->num_rows === 0)
        {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => '존재하지 않는 과목코드입니다.']);
            exit();
        }

        $course = $courseResult->fetch_assoc();
        $stmt->close();

        if ($course['currentEnrollment'] < $course['capacity'])
        {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => '정원이 다 차지 않았습니다. 수강신청 페이지에서 신청해주세요.']);
            exit();
        }

        // 2. 학점 초과 확인
        $totalCredits = $currentCredits + $extraCredits + $course['credits'];
        if ($totalCredits > $maxCredits)
        {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => "신청 가능 학점($maxCredits)을 초과합니다. 현재 학점: $currentCredits, 대기 학점: $extraCredits, 요청 학점: {$course['credits']}"]);
            exit();
        }

        // 3. 시간표 충돌 확인
        // 요청 과목의 시간표 조회
        $courseTimeQuery = "SELECT dayOfWeek, startPeriod, endPeriod FROM CourseTime WHERE courseID = ?";
        $stmt = $conn->prepare($courseTimeQuery);
        $stmt->bind_param("s", $courseID);
        $stmt->execute();
        $courseTimes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // 기존 수강신청 및 빌넣요청 대기 과목의 시간표 조회
        $existingTimesQuery = "SELECT ct.dayOfWeek, ct.startPeriod, ct.endPeriod, c.courseName
                              FROM CourseTime ct
                              JOIN (
                                  SELECT courseID FROM Enroll WHERE userID = ?
                                  UNION
                                  SELECT courseID FROM ExtraEnroll WHERE userID = ? AND extraEnrollStatus = '대기'
                              ) e ON ct.courseID = e.courseID
                              JOIN Course c ON ct.courseID = c.courseID";
        $stmt = $conn->prepare($existingTimesQuery);
        $stmt->bind_param("ss", $studentID, $studentID);
        $stmt->execute();
        $existingTimes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // 충돌 여부 확인
        foreach ($courseTimes as $newTime)
        {
            foreach ($existingTimes as $existingTime)
            {
                if ($newTime['dayOfWeek'] === $existingTime['dayOfWeek'])
                {
                    // 시간대 겹침 확인: 새 과목의 시작 시간이 기존 과목의 종료 시간 전이고, 새 과목의 종료 시간이 기존 과목의 시작 시간 후인 경우
                    if ($newTime['startPeriod'] <= $existingTime['endPeriod'] && $newTime['endPeriod'] >= $existingTime['startPeriod'])
                    {
                        $conn->rollback();
                        $message = sprintf(
                            "시간표가 충돌합니다: '%s' (%s %d-%d)와 '%s' (%s %d-%d)",
                            htmlspecialchars($course['courseName']),
                            htmlspecialchars($newTime['dayOfWeek']),
                            $newTime['startPeriod'],
                            $newTime['endPeriod'],
                            htmlspecialchars($existingTime['courseName']),
                            htmlspecialchars($existingTime['dayOfWeek']),
                            $existingTime['startPeriod'],
                            $existingTime['endPeriod']
                        );
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit();
                    }
                }
            }
        }

        // 4. 이미 수강신청했는지 확인 (Enroll 테이블)
        $alreadyEnrolledQuery = "SELECT * FROM Enroll WHERE userID = ? AND courseID = ?";
        $stmt = $conn->prepare($alreadyEnrolledQuery);
        $stmt->bind_param("ss", $studentID, $courseID);
        $stmt->execute();
        $alreadyEnrolledResult = $stmt->get_result();
        if ($alreadyEnrolledResult->num_rows > 0)
        {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => '이미 수강신청한 과목입니다.']);
            exit();
        }
        $stmt->close();

        // 5. 이미 빌넣요청했는지 확인 (ExtraEnroll 테이블)
        $alreadyRequestedQuery = "SELECT * FROM ExtraEnroll WHERE userID = ? AND courseID = ? AND extraEnrollStatus = '대기'";
        $stmt = $conn->prepare($alreadyRequestedQuery);
        $stmt->bind_param("ss", $studentID, $courseID);
        $stmt->execute();
        $alreadyRequestedResult = $stmt->get_result();
        if ($alreadyRequestedResult->num_rows > 0)
        {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => '이미 빌넣요청한 과목입니다.']);
            exit();
        }
        $stmt->close();

        // 6. 빌넣요청 등록
        $insertQuery = "INSERT INTO ExtraEnroll (userID, courseID, reason, extraEnrollStatus) VALUES (?, ?, ?, '대기')";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("sss", $studentID, $courseID, $reason);
        $success = $stmt->execute();
        if (!$success)
        {
            throw new Exception("빌넣요청 삽입 실패: " . $conn->error);
        }
        $stmt->close();

        // 트랜잭션 커밋
        $conn->commit();
        echo json_encode(['success' => true, 'message' => '빌넣요청이 성공적으로 제출되었습니다.']);
    }
    catch (Exception $e)
    {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '빌넣요청 중 오류가 발생했습니다: ' . htmlspecialchars($e->getMessage())]);
    }
    exit();
}

// 빌넣요청 취소 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancelExtraEnroll')
{
    $courseID = trim($_POST['courseID']);

    // 트랜잭션 시작 - 데이터 일관성 보장
    $conn->begin_transaction();
    try
    {
        // 요청 존재 여부 확인
        $checkQuery = "SELECT * FROM ExtraEnroll WHERE userID = ? AND courseID = ? AND extraEnrollStatus = '대기'";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("ss", $studentID, $courseID);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0)
        {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => '취소할 요청이 존재하지 않습니다.']);
            exit();
        }
        $stmt->close();

        // 요청 삭제
        $deleteQuery = "DELETE FROM ExtraEnroll WHERE userID = ? AND courseID = ?";
        $stmt = $conn->prepare($deleteQuery);
        $stmt->bind_param("ss", $studentID, $courseID);
        $success = $stmt->execute();
        if (!$success)
        {
            throw new Exception("빌넣요청 삭제 실패: " . $conn->error);
        }
        $stmt->close();

        // 트랜잭션 커밋
        $conn->commit();
        echo json_encode(['success' => true, 'message' => '빌넣요청이 취소되었습니다.']);
    }
    catch (Exception $e)
    {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '빌넣요청 취소 중 오류가 발생했습니다: ' . htmlspecialchars($e->getMessage())]);
    }
    exit();
}

// ---------------------------------------
// 검색 로직
// ---------------------------------------

$searchResults = null;
if (isset($_GET['perform_search']) && $_GET['perform_search'] == '1')
{
    $searchType = isset($_GET['searchType']) ? $_GET['searchType'] : 'all';
    $baseQuery = "SELECT c.*, u.userName as professor, d.departmentName, 
                  GROUP_CONCAT(DISTINCT CONCAT(ct.dayOfWeek, ' ', ct.startPeriod, '-', ct.endPeriod) SEPARATOR ', ') as courseTimesFormatted
                  FROM Course c
                  LEFT JOIN User u ON c.professorID = u.userID
                  LEFT JOIN Department d ON c.departmentID = d.departmentID
                  LEFT JOIN CourseTime ct ON c.courseID = ct.courseID";
    $whereClauses = [];
    $params = [];
    $types = "";

    if ($searchType == 'cart')
    {
        $baseQuery .= " JOIN Cart cart_table ON c.courseID = cart_table.courseID";
        $whereClauses[] = "cart_table.userID = ?";
        $params[] = $studentID;
        $types .= "s";
    }
    elseif ($searchType == 'area')
    {
        if (!empty($_GET['detailSearch']))
        {
            $whereClauses[] = "c.area = ?";
            $params[] = $_GET['detailSearch'];
            $types .= "s";
        }
    }
    elseif ($searchType == 'college_department')
    {
        if (!empty($_GET['detailSearch']))
        {
            $whereClauses[] = "d.collegeID = ?";
            $params[] = $_GET['detailSearch'];
            $types .= "i";
        }
        if (!empty($_GET['department']))
        {
            $whereClauses[] = "c.departmentID = ?";
            $params[] = $_GET['department'];
            $types .= "i";
        }
    }

    if (!empty($_GET['keyword']))
    {
        $keyword = '%' . $_GET['keyword'] . '%';
        $whereClauses[] = "(c.courseName LIKE ? OR u.userName LIKE ?)";
        $params[] = $keyword;
        $params[] = $keyword;
        $types .= "ss";
    }

    $searchQuery = $baseQuery;
    if (!empty($whereClauses))
    {
        $searchQuery .= " WHERE " . implode(" AND ", $whereClauses);
    }
    $searchQuery .= " GROUP BY c.courseID ORDER BY c.courseID";

    $stmt_search = $conn->prepare($searchQuery);
    if ($stmt_search)
    {
        if (!empty($params))
        {
            $stmt_search->bind_param($types, ...$params);
        }
        $stmt_search->execute();
        $searchResults = $stmt_search->get_result();
    }
    else
    {
        echo "Error preparing statement: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>순천향대학교 수강신청 시스템 - 빌넣요청</title>
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
            display: flex;
        }

        .sidebar
        {
            width: 200px;
            background-color: #2c3e50;
            color: white;
            height: 100vh;
            padding: 20px 0;
            position: fixed;
        }

        .sidebar ul
        {
            list-style: none;
            padding: 0;
        }

        .sidebar ul li
        {
            padding: 15px 20px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .sidebar ul li:hover
        {
            background-color: #34495e;
        }

        .sidebar ul li.active
        {
            background-color: #3498db;
        }

        .sidebar ul li a
        {
            color: white;
            text-decoration: none;
            font-size: 16px;
        }

        .content
        {
            margin-left: 220px;
            width: calc(100% - 220px);
            padding: 20px;
        }

        .section
        {
            width: 100%;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 0;
            margin-bottom: 30px;
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

        .header
        {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .studentInfo
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

        .searchSection
        {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #eee;
        }

        .searchRow
        {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }

        .searchRow label
        {
            width: 120px;
            font-size: 14px;
            color: #555;
        }

        .searchRow select, .searchRow input
        {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            flex: 1;
        }

        .buttonRow
        {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .button
        {
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .searchButton
        {
            background-color: #00a8ff;
            color: white;
        }

        .searchButton:hover
        {
            background-color: #0090dd;
        }

        .resetButton
        {
            background-color: #f2f2f2;
            color: #333;
        }

        .resetButton:hover
        {
            background-color: #e0e0e0;
        }

        table
        {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 14px;
        }

        table caption
        {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            text-align: left;
            color: #333;
        }

        th, td
        {
            border: 1px solid #ddd;
            padding: 10px;
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

        .courseType
        {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .required
        {
            background-color: #00a8ff;
        }

        .elective
        {
            background-color: #28a745;
        }

        .registerButton
        {
            padding: 5px 10px;
            background-color: #00a8ff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .registerButton:hover
        {
            background-color: #0090dd;
        }

        .deleteButton
        {
            padding: 5px 10px;
            background-color: #ff6b6b;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .deleteButton:hover
        {
            background-color: #ff5252;
        }

        .disabledButton
        {
            padding: 5px 10px;
            background-color: #ccc;
            color: white;
            border: none;
            border-radius: 3px;
            font-size: 12px;
            cursor: not-allowed;
        }

        .modal
        {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content
        {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 5px;
            position: relative;
        }

        .modal-content h3
        {
            margin-bottom: 15px;
        }

        .modal-content label
        {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .modal-content textarea
        {
            width: 100%;
            height: 100px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 15px;
            resize: none;
        }

        .modal-content .button
        {
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .modal-content .submitButton
        {
            background-color: #00a8ff;
            color: white;
        }

        .modal-content .submitButton:hover
        {
            background-color: #0090dd;
        }

        .modal-content .closeButton
        {
            background-color: #f2f2f2;
            color: #333;
        }

        .modal-content .closeButton:hover
        {
            background-color: #e0e0e0;
        }

        .close
        {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <ul>
            <li><a href="cart.php">예비수강신청</a></li>
            <li><a href="enroll.php">수강신청</a></li>
            <li class="active"><a href="extraEnroll.php">빌넣요청</a></li>
        </ul>
    </div>
    <div class="content">
        <!-- 모달 창 -->
        <div id="extraEnrollModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">×</span>
                <h3>빌넣요청</h3>
                <form id="extraEnrollForm">
                    <label for="extraEnrollReason">요청 사유</label>
                    <textarea id="extraEnrollReason" name="reason" placeholder="빌넣요청 사유를 입력하세요 (최대 100자)" maxlength="100" required></textarea>
                    <div class="buttonRow">
                        <button type="button" class="button closeButton" onclick="closeModal()">취소</button>
                        <button type="submit" class="button submitButton">제출</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="section">
            <div class="logo">
                <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
            </div>

            <div class="header">
                <div class="studentInfo">
                    <strong><?= htmlspecialchars($studentInfo['userName']) ?></strong> 님 환영합니다
                    <span>(학과: <?= htmlspecialchars($studentInfo['departmentName']) ?>, 학번: <?= htmlspecialchars($studentID) ?>)</span>
                </div>
                <a href="login.php" class="logoutButton">로그아웃</a>
            </div>

            <!-- 수강신청 검색 -->
            <form method="get" action="<?= $_SERVER['PHP_SELF'] ?>">
                <input type="hidden" name="perform_search" value="1">
                <div class="searchSection">
                    <div class="searchRow">
                        <label for="searchType">검색구분</label>
                        <select id="searchType" name="searchType">
                            <option value="all" <?= (isset($_GET['searchType']) && $_GET['searchType'] == 'all') || !isset($_GET['searchType']) ? 'selected' : '' ?>>전체</option>
                            <option value="cart" <?= isset($_GET['searchType']) && $_GET['searchType'] == 'cart' ? 'selected' : '' ?>>장바구니</option>
                            <option value="area" <?= isset($_GET['searchType']) && $_GET['searchType'] == 'area' ? 'selected' : '' ?>>영역별</option>
                            <option value="college_department" <?= isset($_GET['searchType']) && $_GET['searchType'] == 'college_department' ? 'selected' : '' ?>>단과대학/학과</option>
                        </select>

                        <label for="keyword">검색어</label>
                        <input type="text" id="keyword" name="keyword" placeholder="과목명 또는 교수명"
                            value="<?= isset($_GET['keyword']) ? htmlspecialchars($_GET['keyword']) : '' ?>">
                    </div>

                    <div class="searchRow">
                        <label for="detailSearch">상세검색</label>
                        <select id="detailSearch" name="detailSearch" disabled>
                            <option value="">선택</option>
                            <?php
                            if (isset($_GET['searchType']) && isset($_GET['detailSearch']) && $_GET['detailSearch'] !== '')
                            {
                                $currentSearchType = $_GET['searchType'];
                                $currentDetailSearch = $_GET['detailSearch'];
                                if ($currentSearchType == 'area')
                                {
                                    foreach ($areas as $area_item)
                                    {
                                        echo "<option value=\"".htmlspecialchars($area_item)."\" ".($currentDetailSearch == $area_item ? 'selected' : '').">".htmlspecialchars($area_item)."</option>";
                                    }
                                }
                                elseif ($currentSearchType == 'college_department')
                                {
                                    if ($colleges && $colleges->num_rows > 0)
                                    {
                                        $colleges->data_seek(0);
                                        while ($college = $colleges->fetch_assoc())
                                        {
                                            echo "<option value=\"".$college['collegeID']."\" ".($currentDetailSearch == $college['collegeID'] ? 'selected' : '').">".htmlspecialchars($college['collegeName'])."</option>";
                                        }
                                    }
                                }
                            }
                            ?>
                        </select>

                        <label for="department"></label>
                        <select id="department" name="department" disabled>
                            <option value="">선택</option>
                            <?php
                            if (isset($_GET['searchType']) && $_GET['searchType'] == 'college_department' && isset($_GET['department']) && $_GET['department'] !== '' && isset($_GET['detailSearch']) && $_GET['detailSearch'] !== '')
                            {
                                $currentDepartment = $_GET['department'];
                                $currentCollegeForDept = $_GET['detailSearch'];
                                if ($departments && $departments->num_rows > 0)
                                {
                                    $departments->data_seek(0);
                                    while ($dept = $departments->fetch_assoc())
                                    {
                                        if ($dept['collegeID'] == $currentCollegeForDept)
                                        {
                                            echo "<option value=\"".$dept['departmentID']."\" data-college=\"".$dept['collegeID']."\" ".($currentDepartment == $dept['departmentID'] ? 'selected' : '').">".htmlspecialchars($dept['departmentName'])."</option>";
                                        }
                                    }
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="buttonRow">
                        <button type="submit" class="button searchButton">조회</button>
                        <button type="button" class="button resetButton" onclick="resetSearch()">초기화</button>
                    </div>
                </div>
            </form>

            <!-- 강의 목록 테이블 -->
            <table>
                <caption>강의 목록</caption>
                <thead>
                <tr>
                    <th style="width: 40px;">No.</th>
                    <th style="width: 80px;">이수구분</th>
                    <th style="width: 90px;">과목코드</th>
                    <th style="width: 120px;">교과목명</th>
                    <th style="width: 110px;">학과</th>
                    <th style="width: 110px;">교수명</th>
                    <th style="width: 70px;">학점</th>
                    <th style="width: 140px;">강의시간</th>
                    <th style="width: 110px;">정원/신청</th>
                    <th style="width: 110px;">빌넣요청</th>
                </tr>
                </thead>
                <tbody>
                    <?php
                    if ($searchResults !== null && $searchResults->num_rows > 0)
                    {
                        $rowNum = 1;
                        while ($course = $searchResults->fetch_assoc())
                        {
                            // 이미 요청했는지 확인
                            $isRequested = isset($extraEnrollCourses[$course['courseID']]);
                    ?>
                    <tr>
                        <td><?= $rowNum++ ?></td>
                        <td><?= htmlspecialchars($course['creditType']) ?></td>
                        <td><?= htmlspecialchars($course['courseID']) ?></td>
                        <td><?= htmlspecialchars($course['courseName']) ?></td>
                        <td><?= htmlspecialchars($course['departmentName']) ?></td>
                        <td><?= htmlspecialchars($course['professor']) ?></td>
                        <td><?= htmlspecialchars($course['credits']) ?></td>
                        <td><?= htmlspecialchars($course['courseTimesFormatted']) ?></td>
                        <td><?= htmlspecialchars($course['capacity']) ?>/<?= htmlspecialchars($course['currentEnrollment']) ?></td>
                        <td>
                            <?php
                            if ($isRequested)
                            {
                            ?>
                                <button class="deleteButton" onclick="cancelExtraEnroll('<?= htmlspecialchars($course['courseID']) ?>')">빌넣취소</button>
                            <?php
                            }
                            elseif ($course['currentEnrollment'] < $course['capacity'])
                            {
                            ?>
                                <button class="disabledButton" disabled>빌넣불가</button>
                            <?php
                            }
                            else
                            {
                            ?>
                                <button class="registerButton" onclick="openModal('<?= htmlspecialchars($course['courseID']) ?>')">빌넣요청</button>
                            <?php
                            }
                            ?>
                        </td>
                    </tr>
                    <?php
                        }
                        if ($stmt_search)
                        {
                            $stmt_search->close();
                        }
                    }
                    elseif (isset($_GET['perform_search']) && $_GET['perform_search'] == '1')
                    {
                    ?>
                    <tr>
                        <td colspan="10">검색 결과가 없습니다.</td>
                    </tr>
                    <?php
                    }
                    else
                    {
                    ?>
                    <tr>
                        <td colspan="10">위에서 조회 버튼을 클릭하여 강의를 검색하세요.</td>
                    </tr>
                    <?php
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

<script>
    // PHP에서 가져온 데이터
    const phpAreas = <?= json_encode($areas) ?>;
    const phpColleges = <?= json_encode($colleges_arr_for_js) ?>;
    const phpDepartments = <?= json_encode($departments_arr_for_js) ?>;

    const searchTypeSelect = document.getElementById('searchType');
    const detailSearchSelect = document.getElementById('detailSearch');
    const departmentSelect = document.getElementById('department');
    const keywordInput = document.getElementById('keyword');

    function populateDetailSearch()
    {
        const selectedType = searchTypeSelect.value;
        detailSearchSelect.innerHTML = '<option value="">선택</option>';
        departmentSelect.innerHTML = '<option value="">선택</option>';
        departmentSelect.disabled = true;

        if (selectedType === 'area')
        {
            detailSearchSelect.disabled = false;
            phpAreas.forEach(area =>
            {
                const option = new Option(area, area);
                detailSearchSelect.add(option);
            });
        }
        else if (selectedType === 'college_department')
        {
            detailSearchSelect.disabled = false;
            phpColleges.forEach(college =>
            {
                const option = new Option(college.collegeName, college.collegeID);
                detailSearchSelect.add(option);
            });
        }
        else
        {
            detailSearchSelect.disabled = true;
        }
    }

    function populateDepartments()
    {
        const selectedCollegeID = detailSearchSelect.value;
        departmentSelect.innerHTML = '<option value="">선택</option>';

        if (searchTypeSelect.value === 'college_department' && selectedCollegeID)
        {
            departmentSelect.disabled = false;
            phpDepartments.forEach(dept =>
            {
                if (dept.collegeID == selectedCollegeID)
                {
                    const option = new Option(dept.departmentName, dept.departmentID);
                    departmentSelect.add(option);
                }
            });
        }
        else
        {
            departmentSelect.disabled = true;
        }
    }

    searchTypeSelect.addEventListener('change', function()
    {
        populateDetailSearch();
        populateDepartments();
    });

    detailSearchSelect.addEventListener('change', function()
    {
        if (searchTypeSelect.value === 'college_department')
        {
            populateDepartments();
        }
    });

    document.addEventListener('DOMContentLoaded', function()
    {
        populateDetailSearch();
        const currentDetailSearchVal = '<?= isset($_GET['detailSearch']) ? htmlspecialchars($_GET['detailSearch']) : '' ?>';
        if (currentDetailSearchVal && !detailSearchSelect.disabled)
        {
            detailSearchSelect.value = currentDetailSearchVal;
        }

        populateDepartments();
        const currentDepartmentVal = '<?= isset($_GET['department']) ? htmlspecialchars($_GET['department']) : '' ?>';
        if (currentDepartmentVal && !departmentSelect.disabled)
        {
            departmentSelect.value = currentDepartmentVal;
        }
    });

    function resetSearch()
    {
        searchTypeSelect.value = 'all';
        keywordInput.value = '';
        populateDetailSearch();
        window.location.href = window.location.pathname;
    }

    // 모달 관련 변수
    const modal = document.getElementById('extraEnrollModal');
    const extraEnrollForm = document.getElementById('extraEnrollForm');
    let currentCourseID = null;

    // 모달 열기
    function openModal(courseID)
    {
        currentCourseID = courseID;
        modal.style.display = 'block';
        document.getElementById('extraEnrollReason').value = ''; // 사유 초기화
    }

    // 모달 닫기
    function closeModal()
    {
        modal.style.display = 'none';
        currentCourseID = null;
    }

    // 모달 외부 클릭 시 닫기
    window.onclick = function(event)
    {
        if (event.target == modal)
        {
            closeModal();
        }
    }

    // 폼 제출 처리
    extraEnrollForm.addEventListener('submit', function(event)
    {
        event.preventDefault();
        const reason = document.getElementById('extraEnrollReason').value.trim();

        if (!reason)
        {
            alert('요청 사유를 입력해주세요.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'extraEnroll');
        formData.append('courseID', currentCourseID);
        formData.append('reason', reason);

        fetch('extraEnroll.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data =>
        {
            alert(data.message);
            if (data.success)
            {
                window.location.reload(); // 성공 시 페이지 새로고침
            }
            closeModal();
        })
        .catch(error =>
        {
            console.error('Error:', error);
            alert('요청 처리 중 오류가 발생했습니다.');
            closeModal();
        });
    });

    // 빌넣요청 취소
    function cancelExtraEnroll(courseID)
    {
        if (!confirm('빌넣요청을 취소하시겠습니까?'))
        {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'cancelExtraEnroll');
        formData.append('courseID', courseID);

        fetch('extraEnroll.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data =>
        {
            alert(data.message);
            if (data.success)
            {
                window.location.reload();
            }
        })
        .catch(error =>
        {
            console.error('Error:', error);
            alert('취소 처리 중 오류가 발생했습니다.');
        });
    }
</script>
</body>
</html>
<?php
$conn->close();
?>