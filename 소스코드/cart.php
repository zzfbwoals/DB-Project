<?php
// ---------------------------------------
// 초기 설정
// ---------------------------------------

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

// 요일 매핑
$daysKorean = [
    'Mon' => '월',
    'Tue' => '화',
    'Wed' => '수',
    'Thu' => '목',
    'Fri' => '금',
    'Sat' => '토'
];

$daysEnglish = [
    '월' => 'Mon',
    '화' => 'Tue',
    '수' => 'Wed',
    '목' => 'Thu',
    '금' => 'Fri',
    '토' => 'Sat'
];

// ---------------------------------------
// 데이터 조회
// ---------------------------------------

// 학생 정보 가져오기
$studentID = $_SESSION["userID"];
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

// 장바구니 내역 가져오기
$cartQuery = "SELECT c.*, u.userName as professor, ct.courseName, ct.credits, ct.creditType
              FROM Cart c
              JOIN Course ct ON c.courseID = ct.courseID
              LEFT JOIN User u ON ct.professorID = u.userID
              WHERE c.userID = ?";
$stmt = $conn->prepare($cartQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$cartCourses = $stmt->get_result();
$stmt->close();

// CourseTime 데이터를 별도로 가져오기
$courseTimesQuery = "SELECT ct.courseID, ct.dayOfWeek, ct.startPeriod, ct.endPeriod
                    FROM CourseTime ct
                    WHERE ct.courseID IN (SELECT courseID FROM Cart WHERE userID = ?)";
$stmt = $conn->prepare($courseTimesQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$courseTimesResult = $stmt->get_result();
$stmt->close();

// ---------------------------------------
// 데이터 처리
// ---------------------------------------

// CourseTime 데이터를 courseID별로 그룹화
$courseTimes = [];
while ($time = $courseTimesResult->fetch_assoc())
{
    $courseTimes[$time['courseID']][] = $time;
}

// 총 학점 및 시간표 데이터 초기화
$totalCredits = 0;
$totalCourses = 0;
$timeTable = array(); // 시간표 데이터를 저장하기 위한 배열
$courseColors = array(); // 강의별 색상 생성을 위한 배열

// 장바구니 내역에서 총 학점 계산 및 시간표 데이터 생성
if ($cartCourses->num_rows > 0)
{
    $totalCourses = $cartCourses->num_rows;
    while ($course = $cartCourses->fetch_assoc())
    {
        $totalCredits += $course['credits'];

        // courseID를 기반으로 고유한 색상 생성
        if (!isset($courseColors[$course['courseID']]))
        {
            // courseID를 해시하여 Hue 값 생성 (0~360)
            $hash = crc32($course['courseID']);
            $hue = $hash % 360; // 0~359 사이의 Hue 값
            $saturation = 60; // 채도 60%
            $lightness = 50;  // 밝기 50% (가독성을 위해 너무 어둡거나 밝지 않게)
            $courseColors[$course['courseID']] = "hsl($hue, $saturation%, $lightness%)";
        }

        // 시간표 데이터 저장 (A, B 구분 포함)
        if (isset($courseTimes[$course['courseID']]))
        {
            foreach ($courseTimes[$course['courseID']] as $time)
            {
                $day = $time['dayOfWeek'];
                $start = $time['startPeriod'];
                $end = $time['endPeriod'];

                // A/B가 포함된 경우와 숫자만 있는 경우 구분
                $startNum = (int)preg_replace('/[AB]/', '', $start);
                $endNum = (int)preg_replace('/[AB]/', '', $end);
                $startAB = preg_match('/[AB]/', $start) ? substr($start, -1) : '';
                $endAB = preg_match('/[AB]/', $end) ? substr($end, -1) : '';

                if ($startAB === '' && $endAB === '')
                {
                    // 숫자만 있는 경우: A와 B 모두 표시
                    for ($period = $startNum; $period <= $endNum; $period++)
                    {
                        $timeTable[$day][$period]['A'] = [
                            'courseName' => $course['courseName'],
                            'courseID' => $course['courseID']
                        ];
                        $timeTable[$day][$period]['B'] = [
                            'courseName' => $course['courseName'],
                            'courseID' => $course['courseID']
                        ];
                    }
                }
                else
                {
                    // A/B가 명시된 경우: startAB에서 endAB까지 표시
                    for ($period = $startNum; $period <= $endNum; $period++)
                    {
                        if ($period == $startNum)
                        {
                            // 시작 교시: startAB에 따라 A 또는 B 시작
                            if ($startAB == 'A' || $startAB == '')
                            {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            if ($startAB == 'B' || $startAB == '' || $startAB == 'A')
                            {
                                $timeTable[$day][$period]['B'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                        }
                        elseif ($period == $endNum)
                        {
                            // 종료 교시: endAB에 따라 A 또는 B 끝
                            if ($endAB == 'A' || $endAB == '')
                            {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            if ($endAB == 'B' || $endAB == '')
                            {
                                $timeTable[$day][$period]['B'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            // 종료 교시에서도 A를 포함 (B-B 구간에서 중간 교시처럼 처리)
                            if ($endAB == 'B' && $startAB == 'B' && $period > $startNum)
                            {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                        }
                        else
                        {
                            // 중간 교시: A와 B 모두 표시
                            $timeTable[$day][$period]['A'] = [
                                'courseName' => $course['courseName'],
                                'courseID' => $course['courseID']
                            ];
                            $timeTable[$day][$period]['B'] = [
                                'courseName' => $course['courseName'],
                                'courseID' => $course['courseID']
                            ];
                        }
                    }
                }
            }
        }
    }

    // 쿼리 결과를 다시 얻기 위해 쿼리를 재실행
    $stmt = $conn->prepare($cartQuery);
    $stmt->bind_param("s", $studentID);
    $stmt->execute();
    $cartCourses = $stmt->get_result();
    $stmt->close();
}

// 한 학기 최대 신청 가능 학점 - lastSemesterCredits에 따라 다르게 설정
$maxCredits = ($studentInfo['lastSemesterCredits'] >= 3.0) ? 19 : 18;

// ---------------------------------------
// 장바구니 처리 로직
// ---------------------------------------

// 장바구니에서 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['courseID']) && isset($_POST['action']) && $_POST['action'] === 'remove')
{
    $deleteCourseID = $_POST['courseID'];

    // 트랜잭션 시작 - 데이터 일관성 보장
    $conn->begin_transaction();
    try
    {
        $stmt = $conn->prepare("DELETE FROM Cart WHERE userID = ? AND courseID = ?");
        $stmt->bind_param("ss", $studentID, $deleteCourseID);
        $success = $stmt->execute();
        if (!$success)
        {
            throw new Exception("장바구니 삭제 실패: " . $conn->error);
        }
        $stmt->close();

        // 트랜잭션 커밋
        $conn->commit();
        header("Location: cart.php");
        exit();
    }
    catch (Exception $e)
    {
        $conn->rollback();
        echo "<script>alert('장바구니 삭제 중 오류가 발생했습니다: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='cart.php';</script>";
        exit();
    }
}

// 과목코드로 장바구니 추가 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quickCartCourseID']) && isset($_POST['action']) && ($_POST['action'] === 'quickCart' || $_POST['action'] === 'add'))
{
    $quickCourseID = trim($_POST['quickCartCourseID']);

    // 트랜잭션 시작 - 데이터 일관성 보장
    $conn->begin_transaction();
    try
    {
        // 중복 확인
        $checkQuery = "SELECT * FROM Cart WHERE userID = ? AND courseID = ?";
        $stmt = $conn->prepare($checkQuery);
        $stmt->bind_param("ss", $studentID, $quickCourseID);
        $stmt->execute();
        $checkResult = $stmt->get_result();
        if ($checkResult->num_rows > 0)
        {
            $conn->rollback();
            echo "<script>alert('이미 장바구니에 추가된 과목입니다.'); window.location.href='cart.php';</script>";
            exit();
        }
        $stmt->close();

        // 장바구니 추가
        $insertQuery = "INSERT INTO Cart (userID, courseID) VALUES (?, ?)";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("ss", $studentID, $quickCourseID);
        $success = $stmt->execute();
        if (!$success)
        {
            throw new Exception("장바구니 추가 실패: " . $conn->error);
        }
        $stmt->close();

        // 트랜잭션 커밋
        $conn->commit();
        echo "<script>alert('장바구니에 추가되었습니다.'); window.location.href='cart.php';</script>";
        exit();
    }
    catch (Exception $e)
    {
        $conn->rollback();
        echo "<script>alert('장바구니 추가 중 오류가 발생했습니다: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='cart.php';</script>";
        exit();
    }
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
    <title>순천향대학교 수강신청 시스템 - 예비수강신청</title>
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

        .creditInfo
        {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 5px;
            border: 1px solid #eee;
        }

        .creditBox
        {
            text-align: center;
            flex: 1;
        }

        .creditBox h3
        {
            font-size: 14px;
            color: #555;
            margin-bottom: 5px;
        }

        .creditBox p
        {
            font-size: 20px;
            color: #00a8ff;
            font-weight: bold;
        }

        .creditBox .maxCredit
        {
            color: #ff6b6b;
        }

        .contentWrapper
        {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .courseList
        {
            flex: 2;
        }

        .timeTable
        {
            flex: 1;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }

        .timeTable table
        {
            width: 100%;
            border-collapse: collapse;
        }

        .timeTable th, .timeTable td
        {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
            font-size: 12px;
            height: 25px;
        }

        .timeTable th
        {
            background-color: #f2f2f2;
        }

        .timeTable .time
        {
            width: 30px;
            background-color: #f2f2f2;
            font-weight: bold;
        }

        .timeTable .course
        {
            color: white;
            font-size: 11px;
        }

        <?php
        foreach ($courseColors as $courseID => $color)
        {
            echo ".course-$courseID { background-color: $color; }\n";
        }
        ?>

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

        .notification
        {
            font-size: 12px;
            color: #666;
            margin-top: 10px;
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            justify-content: space-between;
        }

        .notification img
        {
            width: 16px;
            margin-right: 5px;
        }

        .quickCartContainer
        {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quickCartContainer input
        {
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 120px;
            font-size: 12px;
        }

        .quickCartContainer button
        {
            padding: 5px 10px;
            background-color: #00a8ff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .quickCartContainer button:hover
        {
            background-color: #0090dd;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <ul>
            <li class="active"><a href="cart.php">예비수강신청</a></li>
            <li><a href="enroll.php">수강신청</a></li>
            <li><a href="extraEnroll.php">빌넣요청</a></li>
        </ul>
    </div>
    <div class="content">
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

            <div class="creditInfo">
                <div class="creditBox">
                    <h3>현재 예비 학점</h3>
                    <p><?= $totalCredits ?> 학점</p>
                </div>
                <div class="creditBox">
                    <h3>예비 교과목 수</h3>
                    <p><?= $totalCourses ?> 과목</p>
                </div>
                <div class="creditBox">
                    <h3>신청 가능 학점</h3>
                    <p class="maxCredit"><?= $maxCredits ?> 학점</p>
                </div>
            </div>

            <div class="contentWrapper">
                <div class="courseList">
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>이수구분</th>
                                <th>과목코드</th>
                                <th>교과목명</th>
                                <th>교수명</th>
                                <th>학점</th>
                                <th>강의시간</th>
                                <th>관리</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rowNum = 1;
                            if ($cartCourses->num_rows > 0)
                            {
                                while ($course = $cartCourses->fetch_assoc())
                                {
                                    $timeSlots = [];
                                    if (isset($courseTimes[$course['courseID']]))
                                    {
                                        foreach ($courseTimes[$course['courseID']] as $time)
                                        {
                                            $day = isset($daysKorean[$time['dayOfWeek']]) ? $daysKorean[$time['dayOfWeek']] : $time['dayOfWeek'];
                                            $timeSlots[] = "$day {$time['startPeriod']}-{$time['endPeriod']}";
                                        }
                                    }
                                    $timeDisplay = implode('/', $timeSlots);
                            ?>
                            <tr>
                                <td><?= $rowNum++ ?></td>
                                <td><?= htmlspecialchars($course['creditType']) ?></td>
                                <td><?= htmlspecialchars($course['courseID']) ?></td>
                                <td><?= htmlspecialchars($course['courseName']) ?></td>
                                <td><?= htmlspecialchars($course['professor']) ?></td>
                                <td><?= htmlspecialchars($course['credits']) ?></td>
                                <td><?= htmlspecialchars($timeDisplay) ?></td>
                                <td>
                                    <form method="post" action="cart.php" style="display:inline;" onsubmit="return confirm('정말로 이 과목을 삭제하시겠습니까?');">
                                        <input type="hidden" name="courseID" value="<?= $course['courseID'] ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <button type="submit" class="deleteButton">삭제</button>
                                    </form>
                                </td>
                            </tr>
                            <?php
                                }
                            }
                            else
                            {
                            ?>
                            <tr>
                                <td colspan="8">예비수강신청 내역이 없습니다.</td>
                            </tr>
                            <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="timeTable">
                    <table>
                        <thead>
                            <tr>
                                <th></th>
                                <th>Mon</th>
                                <th>Tue</th>
                                <th>Wed</th>
                                <th>Thu</th>
                                <th>Fri</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // 시간표를 1교시부터 9교시까지 반복, 각 교시에 A와 B 추가
                            for ($i = 1; $i <= 9; $i++)
                            {
                                echo "<tr>";
                                echo "<td class='time' rowspan='2'>$i</td>";
                                foreach (array('월', '화', '수', '목', '금') as $day)
                                {
                                    $dayEng = $daysEnglish[$day];
                                    echo "<td ";
                                    if (isset($timeTable[$day][$i]['A']))
                                    {
                                        $courseID = $timeTable[$day][$i]['A']['courseID'];
                                        echo "class='course course-$courseID' title='" .
                                            htmlspecialchars($timeTable[$day][$i]['A']['courseName'], ENT_QUOTES, 'UTF-8') . "'>";
                                        echo htmlspecialchars(mb_substr($timeTable[$day][$i]['A']['courseName'], 0, 3, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                                        if (mb_strlen($timeTable[$day][$i]['A']['courseName'], 'UTF-8') > 3)
                                        {
                                            echo "...";
                                        }
                                    }
                                    else
                                    {
                                        echo ">";
                                    }
                                    echo "</td>";
                                }
                                echo "</tr>";

                                echo "<tr>";
                                foreach (array('월', '화', '수', '목', '금') as $day)
                                {
                                    $dayEng = $daysEnglish[$day];
                                    echo "<td ";
                                    if (isset($timeTable[$day][$i]['B']))
                                    {
                                        $courseID = $timeTable[$day][$i]['B']['courseID'];
                                        echo "class='course course-$courseID' title='" .
                                            htmlspecialchars($timeTable[$day][$i]['B']['courseName'], ENT_QUOTES, 'UTF-8') . "'>";
                                        echo htmlspecialchars(mb_substr($timeTable[$day][$i]['B']['courseName'], 0, 3, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                                        if (mb_strlen($timeTable[$day][$i]['B']['courseName'], 'UTF-8') > 3)
                                        {
                                            echo "...";
                                        }
                                    }
                                    else
                                    {
                                        echo ">";
                                    }
                                    echo "</td>";
                                }
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

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

            <div class="notification">
                <div style="display: flex; align-items: center;">
                    <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNiIgaGVpZ2h0PSIxNiIgdmlld0JveD0iMCAwIDE2IDE2IiBmaWxsPSIjOTk5Ij48cGF0aCBkPSJNOCAxQzQuMTQgMSAxIDQuMTQgMSA4QzEgMTEuODYgNC4xNCAxNSA4IDE1QzExLjg2IDE1IDE1IDExLjg2IDE1IDhDMTUgNC4xNCAxMS44NiAxIDggMU0gOCAxNkM0LjY4NiAxNiAyIDE0LjMxNCAyIDEwQzIgNS42ODYgNC42ODYgMiA4IDJDMTEuMzEzIDIgMTQgNS42ODYgMTQgMTBDMTQgMTQuMzE0IDExLjMxMyAxNiA4IDE2Ij48L3BhdGg+PHBhdGggZD0iTTcgM0g5VjlIN1YzWk0gNyAxMUg5VjEzSDdWMTFaIj48L3BhdGg+PC9zdmc+" alt="정보">
                    실시간으로 예비수강신청 상태가 반영됩니다. 모든 추가는 시스템에 즉시 기록됩니다.
                </div>
                <div class="quickCartContainer">
                    <form method="post" action="cart.php" id="quickCartForm" style="display: flex; align-items: center; gap: 10px;">
                        <input type="text" id="quickCartCourseID" name="quickCartCourseID" placeholder="과목코드 입력" required>
                        <input type="hidden" name="action" value="quickCart">
                        <button type="submit" onclick="return quickCartSubmit();">추가</button>
                    </form>
                </div>
            </div>

            <table>
                <caption>강의 목록</caption>
                <thead>
                <tr>
                    <th style="width: 40px;">No.</th>
                    <th style="width: 80px;">이수구분</th>
                    <th style="width: 87px;">과목코드</th>
                    <th style="width: 120px;">교과목명</th>
                    <th style="width: 100px;">학과</th>
                    <th style="width: 70px;">교수명</th>
                    <th style="width: 70px;">학점</th>
                    <th style="width: 110px;">강의시간</th>
                    <th style="width: 110px;">추가</th>
                </tr>
                </thead>
                <tbody>
                    <?php
                    if ($searchResults !== null && $searchResults->num_rows > 0)
                    {
                        $rowNum = 1;
                        while ($course = $searchResults->fetch_assoc())
                        {
                            // 이미 장바구니에 추가된 강의인지 확인
                            $alreadyInCart = false;
                            if ($cartCourses && $cartCourses->num_rows > 0)
                            {
                                $cartCourses->data_seek(0);
                                while ($cartCourseItem = $cartCourses->fetch_assoc())
                                {
                                    if ($cartCourseItem['courseID'] == $course['courseID'])
                                    {
                                        $alreadyInCart = true;
                                        break;
                                    }
                                }
                            }
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
                        <td>
                            <?php
                            if ($alreadyInCart)
                            {
                            ?>
                                <form method="post" action="cart.php" style="display:inline;" onsubmit="return confirm('이미 추가된 과목입니다. 정말 삭제하시겠습니까?');">
                                    <input type="hidden" name="courseID" value="<?= $course['courseID'] ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <button type="submit" class="deleteButton">삭제</button>
                                </form>
                            <?php
                            }
                            else
                            {
                            ?>
                                <button class="registerButton" onclick="addToCart('<?= htmlspecialchars($course['courseID']) ?>')">추가</button>
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
                        <td colspan="9">검색 결과가 없습니다.</td>
                    </tr>
                    <?php
                    }
                    else
                    {
                    ?>
                    <tr>
                        <td colspan="9">위에서 조회 버튼을 클릭하여 강의를 검색하세요.</td>
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

    function submitAdd(courseID, actionType)
    {
        const quickCartForm = document.getElementById('quickCartForm');
        const courseIDInput = document.getElementById('quickCartCourseID');
        const actionInput = quickCartForm.querySelector('input[name="action"]');

        if (!courseID)
        {
            alert('과목코드를 입력해주세요.');
            return false;
        }

        courseIDInput.value = courseID;
        actionInput.value = actionType;
        quickCartForm.submit();
        return true;
    }

    function quickCartSubmit()
    {
        const courseIDInput = document.getElementById('quickCartCourseID').value.trim();
        return submitAdd(courseIDInput, 'quickCart');
    }

    function addToCart(courseID)
    {
        submitAdd(courseID, 'add');
    }
</script>
</body>
</html>
<?php
$conn->close();
?>