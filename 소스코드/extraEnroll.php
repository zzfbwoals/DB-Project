<?php
header('Content-Type: text/html; charset=UTF-8');
session_start();

if (!isset($_SESSION["userID"]) || $_SESSION["userRole"] !== 'student') {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "dbproject_user", "Gkrrytlfj@@33", "dbproject");
if ($conn->connect_error) die("DB 연결 실패: " . $conn->connect_error);
$conn->set_charset("utf8");

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

$areaQuery = "SELECT DISTINCT area FROM Course WHERE area IS NOT NULL AND area != '' ORDER BY area";
$areaResult = $conn->query($areaQuery);
$areas = [];
if ($areaResult) {
    while ($row = $areaResult->fetch_assoc()) {
        $areas[] = $row['area'];
    }
    $areaResult->free();
}
$areas_normal = [];
$areas_core = [];
foreach ($areas as $area_item) {
    if (strpos($area_item, '(중핵)') !== false) {
        $areas_core[] = $area_item;
    } else {
        $areas_normal[] = $area_item;
    }
}
sort($areas_normal);
sort($areas_core);
$areas = array_merge($areas_normal, $areas_core);

$collegesQuery = "SELECT * FROM College ORDER BY collegeName";
$colleges = $conn->query($collegesQuery);
$colleges_arr_for_js = [];
if ($colleges && $colleges->num_rows > 0) {
    $colleges_arr_for_js = $colleges->fetch_all(MYSQLI_ASSOC);
    $colleges->data_seek(0);
}

$departmentsQuery = "SELECT d.*, c.collegeName FROM Department d 
                     JOIN College c ON d.collegeID = c.collegeID 
                     ORDER BY c.collegeName, d.departmentName";
$departments = $conn->query($departmentsQuery);
$departments_arr_for_js = [];
if ($departments && $departments->num_rows > 0) {
    $departments_arr_for_js = $departments->fetch_all(MYSQLI_ASSOC);
    $departments->data_seek(0);
}

$searchResults = null;
if (isset($_GET['perform_search']) && $_GET['perform_search'] == '1') {
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
    
    if ($searchType == 'cart') {
        $baseQuery .= " JOIN Cart cart_table ON c.courseID = cart_table.courseID";
        $whereClauses[] = "cart_table.userID = ?";
        $params[] = $studentID;
        $types .= "s";
    } elseif ($searchType == 'area') {
        if (!empty($_GET['detailSearch'])) {
            $whereClauses[] = "c.area = ?";
            $params[] = $_GET['detailSearch'];
            $types .= "s";
        }
    } elseif ($searchType == 'college_department') {
        if (!empty($_GET['detailSearch'])) {
            $whereClauses[] = "d.collegeID = ?";
            $params[] = $_GET['detailSearch'];
            $types .= "i";
        }
        if (!empty($_GET['department'])) {
            $whereClauses[] = "c.departmentID = ?";
            $params[] = $_GET['department'];
            $types .= "i";
        }
    }

    if (!empty($_GET['keyword'])) {
        $keyword = '%' . $_GET['keyword'] . '%';
        $whereClauses[] = "(c.courseName LIKE ? OR u.userName LIKE ?)";
        $params[] = $keyword;
        $params[] = $keyword;
        $types .= "ss";
    }

    $searchQuery = $baseQuery;
    if (!empty($whereClauses)) {
        $searchQuery .= " WHERE " . implode(" AND ", $whereClauses);
    }
    $searchQuery .= " GROUP BY c.courseID ORDER BY c.courseID";

    $stmt_search = $conn->prepare($searchQuery);
    if ($stmt_search) {
        if (!empty($params)) {
            $stmt_search->bind_param($types, ...$params);
        }
        $stmt_search->execute();
        $searchResults = $stmt_search->get_result();
    } else {
        echo "Error preparing statement: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<!-- 위의 <head>와 <body> 코드를 이어서 추가 -->

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

        body {
            display: flex;
        }

        .sidebar {
            width: 200px;
            background-color: #2c3e50;
            color: white;
            height: 100vh;
            padding: 20px 0;
            position: fixed;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
        }

        .sidebar ul li {
            padding: 15px 20px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .sidebar ul li:hover {
            background-color: #34495e;
        }

        .sidebar ul li.active {
            background-color: #3498db;
        }

        .sidebar ul li a {
            color: white;
            text-decoration: none;
            font-size: 16px;
        }

        .content {
            margin-left: 220px;
            width: calc(100% - 220px);
            padding: 20px;
        }

        .section {
            width: 100%;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 0;
            margin-bottom: 30px;
        }

        .logo {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo img {
            width: 200px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .studentInfo {
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

        .searchSection {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #eee;
        }

        .searchRow {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }

        .searchRow label {
            width: 120px;
            font-size: 14px;
            color: #555;
        }

        .searchRow select, .searchRow input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            flex: 1;
        }

        .buttonRow {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .button {
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .searchButton {
            background-color: #00a8ff;
            color: white;
        }

        .searchButton:hover {
            background-color: #0090dd;
        }

        .resetButton {
            background-color: #f2f2f2;
            color: #333;
        }

        .resetButton:hover {
            background-color: #e0e0e0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 14px;
        }

        table caption {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            text-align: left;
            color: #333;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 10px;
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

        .courseType {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .required {
            background-color: #00a8ff;
        }

        .elective {
            background-color: #28a745;
        }

        .registerButton {
            padding: 5px 10px;
            background-color: #00a8ff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .registerButton:hover {
            background-color: #0090dd;
        }

        .deleteButton {
            padding: 5px 10px;
            background-color: #ff6b6b;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .deleteButton:hover {
            background-color: #ff5252;
        }

        .disabledButton {
            padding: 5px 10px;
            background-color: #ccc;
            color: white;
            border: none;
            border-radius: 3px;
            font-size: 12px;
            cursor: not-allowed;
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
                <input type="hidden" name="perform_search" value="1"> <!-- 검색 실행 플래그 -->
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
                            if (isset($_GET['searchType']) && isset($_GET['detailSearch']) && $_GET['detailSearch'] !== '') {
                                $currentSearchType = $_GET['searchType'];
                                $currentDetailSearch = $_GET['detailSearch'];
                                if ($currentSearchType == 'area') {
                                    foreach ($areas as $area_item) {
                                        echo "<option value=\"".htmlspecialchars($area_item)."\" ".($currentDetailSearch == $area_item ? 'selected' : '').">".htmlspecialchars($area_item)."</option>";
                                    }
                                } elseif ($currentSearchType == 'college_department') {
                                    if ($colleges && $colleges->num_rows > 0) {
                                        $colleges->data_seek(0);
                                        while ($college = $colleges->fetch_assoc()) {
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
                            if (isset($_GET['searchType']) && $_GET['searchType'] == 'college_department' && isset($_GET['department']) && $_GET['department'] !== '' && isset($_GET['detailSearch']) && $_GET['detailSearch'] !== '') {
                                $currentDepartment = $_GET['department'];
                                $currentCollegeForDept = $_GET['detailSearch'];
                                if ($departments && $departments->num_rows > 0) {
                                    $departments->data_seek(0);
                                    while ($dept = $departments->fetch_assoc()) {
                                        if ($dept['collegeID'] == $currentCollegeForDept) {
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
                    if ($searchResults !== null && $searchResults->num_rows > 0) {
                        $rowNum = 1;
                        while ($course = $searchResults->fetch_assoc()) {
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
                            <?php if ($course['currentEnrollment'] < $course['capacity']) { ?>
                                <button class="disabledButton" disabled>빌넣불가</button>
                            <?php } else { ?>
                                <button class="registerButton" onclick="alert('빌넣요청 기능은 현재 구현되지 않았습니다.');">빌넣요청</button>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php
                        }
                        if ($stmt_search) $stmt_search->close();
                    } else if (isset($_GET['perform_search']) && $_GET['perform_search'] == '1') {
                    ?>
                    <tr>
                        <td colspan="10">검색 결과가 없습니다.</td>
                    </tr>
                    <?php } else { ?>
                    <tr>
                        <td colspan="10">위에서 조회 버튼을 클릭하여 강의를 검색하세요.</td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    const phpAreas = <?= json_encode($areas) ?>;
    const phpColleges = <?= json_encode($colleges_arr_for_js) ?>;
    const phpDepartments = <?= json_encode($departments_arr_for_js) ?>;

    const searchTypeSelect = document.getElementById('searchType');
    const detailSearchSelect = document.getElementById('detailSearch');
    const departmentSelect = document.getElementById('department');
    const keywordInput = document.getElementById('keyword');

    function populateDetailSearch() {
        const selectedType = searchTypeSelect.value;
        detailSearchSelect.innerHTML = '<option value="">선택</option>';
        departmentSelect.innerHTML = '<option value="">선택</option>';
        departmentSelect.disabled = true;

        if (selectedType === 'area') {
            detailSearchSelect.disabled = false;
            phpAreas.forEach(area => {
                const option = new Option(area, area);
                detailSearchSelect.add(option);
            });
        } else if (selectedType === 'college_department') {
            detailSearchSelect.disabled = false;
            phpColleges.forEach(college => {
                const option = new Option(college.collegeName, college.collegeID);
                detailSearchSelect.add(option);
            });
        } else {
            detailSearchSelect.disabled = true;
        }
    }

    function populateDepartments() {
        const selectedCollegeID = detailSearchSelect.value;
        departmentSelect.innerHTML = '<option value="">선택</option>';

        if (searchTypeSelect.value === 'college_department' && selectedCollegeID) {
            departmentSelect.disabled = false;
            phpDepartments.forEach(dept => {
                if (dept.collegeID == selectedCollegeID) {
                    const option = new Option(dept.departmentName, dept.departmentID);
                    departmentSelect.add(option);
                }
            });
        } else {
            departmentSelect.disabled = true;
        }
    }

    searchTypeSelect.addEventListener('change', function() {
        populateDetailSearch();
        populateDepartments();
    });

    detailSearchSelect.addEventListener('change', function() {
        if (searchTypeSelect.value === 'college_department') {
            populateDepartments();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        populateDetailSearch();
        const currentDetailSearchVal = '<?= isset($_GET['detailSearch']) ? htmlspecialchars($_GET['detailSearch']) : '' ?>';
        if (currentDetailSearchVal && !detailSearchSelect.disabled) {
            detailSearchSelect.value = currentDetailSearchVal;
        }

        populateDepartments();
        const currentDepartmentVal = '<?= isset($_GET['department']) ? htmlspecialchars($_GET['department']) : '' ?>';
        if (currentDepartmentVal && !departmentSelect.disabled) {
            departmentSelect.value = currentDepartmentVal;
        }
    });

    function resetSearch() {
        searchTypeSelect.value = 'all';
        keywordInput.value = '';
        populateDetailSearch();
        window.location.href = window.location.pathname;
    }
</script>
</body>
</html>
<?php
$conn->close();
// TODO: 후에 수강신청 종료 후 정정기간(예: 2025-05-26 ~ 2025-05-30) 동안만 빌넣 요청 가능하도록 기간 제한 추가.
?>