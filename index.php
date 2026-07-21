<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.9, maximum-scale=0.9, user-scalable=no">

    <title>Secure Info Store</title>
    <script src="js/js.php"></script>
    <link rel="stylesheet" href="css/main.css">

    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="js/ext/crypto-js.min.js"></script>
</head>

<body>
    <div id="main-container">
        <div class="login-container">
            <h2>Login Secure Information Store</h2>
            <form method="post" action="" id="loginform">
                <div class=" form-group">
                    <label for="userID">Store ID</label>
                    <input type="text" id="userID" name="userID" placeholder="Enter Store ID" value="test123" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your Password" required>
                </div>
                <div>Shared Zugriff mit Seed</div>
                <div id="acessbyseed">
                    <p>
                        Falls du einen Seed und den Namen eines geteilten Info-Stores bekommen hast, kannst Du hier Zugang bekommen.
                    </p>
                    <p>Bitte beachte, falls der Inhaber aktivert hat, dass er den Zugriff blockieren kann erhält er eine Email.</p>

                    <label for="seed">Geben Sie den Seed ein:</label>
                    <div class="form-group form-big" style="display: grid; grid-template-columns: 1fr 1fr 1fr;">
                        <div class="seed"><input type="text" id="seed_1" name="seed_1" value="pear"></div>
                        <div class="seed"><input type="text" id="seed_2" name="seed_2" value="rigid"></div>
                        <div class="seed"><input type="text" id="seed_3" name="seed_3" value="cheap"></div>
                        <div class="seed"><input type="text" id="seed_4" name="seed_4" value="lady"></div>
                        <div class="seed"><input type="text" id="seed_5" name="seed_5" value="glove"></div>
                        <div class="seed"><input type="text" id="seed_6" name="seed_6" value="stable"></div>
                        <div class="seed"><input type="text" id="seed_7" name="seed_7" value="stock"></div>
                        <div class="seed"><input type="text" id="seed_8" name="seed_8" value="quiz"></div>
                        <div class="seed"><input type="text" id="seed_9" name="seed_9" value="nuclear"></div>
                        <div class="seed"><input type="text" id="seed_10" name="seed_10" value="tortoise"></div>
                        <div class="seed"><input type="text" id="seed_11" name="seed_11" value="physical"></div>
                        <div class="seed"><input type="text" id="seed_12" name="seed_12" value="gym"></div>
                    </div>
                </div>
                <div class="form-group">
                    <button type="button" class="login-button" onclick="validateLoginForm();">Login</button>
                </div>
            </form>
        </div>

    </div>
    <div class="statusbar">
        <div class="statuslinks" style="display:none;">
            <div id="btlogout" class="statusbuttons">logout</div>
            <div id="btshare" class="statusbuttons">share Store</div>
        </div>
        <div id="statusmsg">Dies ist eine Meldung!</div>
    </div>
</body>

</html>