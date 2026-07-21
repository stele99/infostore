class m_share {
  static fillSeed() {
    const numbers = m_share.getRandomNumbers(0, wordlist.length, 12);

    let cnt = 1;
    numbers.forEach(function (number) {
      $("#seed_" + cnt).val(wordlist[number]);
      cnt = cnt + 1;
    });
  }

  static getRandomNumbers(min, max, count) {
    const randomNumbers = [];
    const array = new Uint32Array(count);

    // Generate cryptographically secure random values
    window.crypto.getRandomValues(array);

    // Scale and map the values to the range [min, max]
    for (let i = 0; i < array.length; i++) {
      const randomNumber =
        Math.floor((array[i] / (0xffffffff + 1)) * (max - min + 1)) + min;
      randomNumbers.push(randomNumber);
    }

    return randomNumbers;
  }

  /* create new share with crypted keys and information */
  static createShare() {
    if (userId != se_crypt.getHash($("#userId").val())) {
      alert("Store ID stimmt nicht mit aktuellem Store überein!", "critical");
      return;
    }

    let phrase = "";
    for (let i = 1; i <= 12; i++) {
      phrase = phrase + $("#seed_" + i).val();
    }

    // Key generieren
    let uid = se_crypt.getUUID();
    let key2 = se_crypt.getKey(phrase, userId);
    let keyb64 = key.toString(CryptoJS.enc.Base64);

    let encKey = se_crypt.symCrypt(keyb64, key2);
    let iv = encKey.iv;
    let encMail = se_crypt.symCrypt($("#f_email").val(), key, iv);
    let encDelay = se_crypt.symCrypt($("#f_duration").val(), key, iv);
    let encStatus = se_crypt.symCrypt("new", key, iv);
    let encTsStatus = se_crypt.symCrypt(Date.now().toString(), key, iv);
    let adata = {
      uid: uid,
      storeid: userId,
      key: encKey.msg,
      mail: encMail.msg,
      delay: encDelay.msg,
      iv: iv,
      status: encStatus.msg,
      ts_status: encTsStatus.msg,
    };

    sendData(adata, "createshare", function (data) {
      console.log(data);
    });
  }

  /* Mit Seed einloggen */
  static loginBySeed() {
    let phrase = "";
    for (let i = 1; i <= 12; i++) {
      phrase = phrase + $("#seed_" + i).val();
    }

    // Store ableiten:
    const userId = document.getElementById("userID").value;
    let userIdHashed = se_crypt.getHash(userId);
    let key2 = se_crypt.getKey(phrase, userIdHashed);

    let adata = {
      storeid: userIdHashed,
    };

    sendData(adata, "sharerequestlogin", function (data) {
      let keyfound = "";
      let share;

      // Informationen auf dem Server wurden gelesen versuchen ob der Key passt
      data.forEach(function (row) {
        // Try to check Decryption:
        let key = se_crypt.symDecrypt(row.key, key2, row.iv);
        if (key.toString() != "") {
          keyfound = key.toString();
          share = row;
        }
      });

      if (keyfound.length < 3) {
        alert("Seed nicht valide!", "warning");
      } else {
        // Key gefunden.
        key = CryptoJS.enc.Base64.parse(keyfound);
        let mail = se_crypt.symDecrypt(share.mail, key, share.iv);
        let delay = se_crypt.symDecrypt(share.delay, key, share.iv);
        let status = se_crypt.symDecrypt(share.status, key, share.iv);
        let ts_status = se_crypt.symDecrypt(share.ts_status, key, share.iv);

        let uid = share.uid;
        let storeid = share.storeid;

        console.log(mail + "  " + delay + ":  " + status + ", " + ts_status);
        if (delay > 0 && status == "new") {
          // Send Mail to recipient and Inform him about access
          let status_new = se_crypt.symCrypt("request", key, share.iv);
          let adata = {
            storeid: storeid,
            mail: mail,
            status: status_new,
            ts_status: se_crypt.symCrypt(Date.now().toString(), key, share.iv),
          };
          sendData(adata, "shareaccess1", function (data) {});
        } else if (delay > 0 && status == "request") {
          // prüfen ob Wartezeit erfüllt
        } else if (delay > 0 && status == "denied") {
          alert("Zugriff wurde vom Inhaber abgelehnt", "error");
          // TODO: Zurücksetzen auf Status NEW.
        } else if (delay == 0 && stautus == "new") {
          // Access Granted
          // TODO: Implementierung
        }
      }
    });
  }
}
