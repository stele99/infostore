var key;
var userId;
var eC;

/* ----------------------------------------------------------
   Login Funktion
 ----------------------------------------------------------*/
function validateLoginForm() {
  const userIdForm = document.getElementById("userID").value;
  const masterPassword = document.getElementById("password").value;
  const seed1 = document.getElementById("seed_1").value;
  if (masterPassword == "" && seed1.length > 2) {
    m_share.loginBySeed();
    return;
  }

  if (userIdForm.length < 5) {
    alert("Die UserId muss mindestens 5 Zeichen lang sein.");
    return false;
  }

  if (masterPassword.length < 12) {
    alert("Das Passwort muss mindestens 12 Zeichen lang sein.");
    return false;
  }

  key = se_crypt.getKey(masterPassword, userIdForm);
  keyb64 = key.toString(CryptoJS.enc.Base64);
  verify = se_crypt.symCrypt("CRYPT_VERIFICATION", key);
  localStorage.setItem("v", verify.msg);
  localStorage.setItem("i", verify.iv);
  localStorage.setItem("key", keyb64);
  let userIdHashed = se_crypt.getHash(userIdForm);
  localStorage.setItem("userId", userIdHashed);

  let pwHash = se_crypt.getHash(
    masterPassword.substring(0, 2) + masterPassword.slice(-1)
  );

  let adata = {
    userid: userIdHashed,
    verification: verify.msg + "■" + verify.iv,
    pw: pwHash,
  };

  sendData(adata, "userlogin", function (data) {
    userId = localStorage.getItem("userId");
    if (data == "") {
      alert("Not authorized!", "critical");
      localStorage.setItem("v", "");
      localStorage.setItem("i", "");
      localStorage.setItem("key", "");
      localStorage.setItem("userId", "");
      userId = "";
      return;
    }
    let verify = data.split("■", 2);
    ret = se_crypt.symDecrypt(verify[0], key, verify[1]);

    if (ret == "CRYPT_VERIFICATION") {
      eC = new m_entry();
      $("#main-container").load("edit.php");
    } else {
      alert("Not authorized!", "critical");
      localStorage.setItem("v", "");
      localStorage.setItem("i", "");
      localStorage.setItem("key", "");
      localStorage.setItem("userId", "");
      userId = "";
    }
  });
}

/* ----------------------------------------------------------
   INIT Funktion für jeden Aufruf
 ----------------------------------------------------------*/
function init() {
  userId = localStorage.getItem("userId");
  keyb64 = localStorage.getItem("key") + "";

  if (keyb64.length > 5) {
    key = CryptoJS.enc.Base64.parse(keyb64);
    vi = localStorage.getItem("i");
    vm = localStorage.getItem("v");
    ret = se_crypt.symDecrypt(vm, key, vi);
    eC = new m_entry();
    if (ret == "CRYPT_VERIFICATION") $("#main-container").load("edit.php");
  }
}

/* ----------------------------------------------------------
   AJAX allgemeine Send-Function
 ----------------------------------------------------------*/
function sendData(data, method, callBackFunc = "") {
  let url = "ajax.php?m=" + method;
  $.ajax({
    url: url,
    type: "POST",
    contentType: "application/json", // Specify that the content is JSON
    data: JSON.stringify(data), // Convert adata to JSON string
    success: function (response) {
      let retCode = response.status;
      let retMsg = response.msg;
      if (typeof callBackFunc === "function") {
        callBackFunc(response.data);
      }
      if (retCode >= 200 && retCode < 300) {
        alert(retMsg, "ok");
      } else {
        alert(retMsg, "alert");
      }
    },
    error: function (xhr, status, error) {
      alert("Error: \n" + error);
      console.log("ERROR:");
      console.log(xhr);
      console.log(status);
    },
  });
}

function alert(msg, type = "") {
  let icon = "&#x1F538;";
  if (type == "ok") {
    icon = "&#x2705;";
  } else if (type == "warning") {
    icon = "&#x2705;";
  } else if (type == "alert") {
    icon = "&#x26D4;";
  }
  $("#statusmsg").html(icon + " " + msg);
  $("#statusmsg").fadeIn("fast", function () {
    $(this).delay(5000).fadeOut("slow");
  });
}
