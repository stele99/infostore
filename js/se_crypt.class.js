/* Wrapper CLass for crypto.JS with AES Encryption and forge.js with RSA Encryption */
class se_crypt {
  static iterations = 2 ^ 88;

  static getIV(passphrase) {
    var bytesInSalt = 128 / 8;
    var salt = CryptoJS.lib.WordArray.random(bytesInSalt);
    return CryptoJS.PBKDF2(passphrase, salt, {
      keySize: 4,
      iterations: se_crypt.iterations,
    });
  }
  static getKey(passphrase, salt) {
    // NO salt used! - same Key is returned every time.
    // Necessary for Login
    salt = CryptoJS.enc.Utf8.parse(salt);
    return CryptoJS.PBKDF2(passphrase.toString(), salt, {
      keySize: 256 / 32,
      iterations: se_crypt.iterations,
    });
  }

  static symCrypt(message, key, ivB64 = "") {
    if (ivB64 == "") {
      var iv = this.getIV(this.randomString(8));
    } else {
      var iv = CryptoJS.enc.Base64.parse(ivB64);
    }
    var enc = CryptoJS.AES.encrypt(message, key, { iv: iv }); // , format: JsonFormatter
    var encB64 = enc.ciphertext.toString(CryptoJS.enc.Base64);
    var ivB64 = iv.toString(CryptoJS.enc.Base64);
    return {
      msg: encB64,
      iv: ivB64,
    };
  }

  /* generate SHA256 Hash */
  static getHash(text) {
    return CryptoJS.SHA256(text).toString(CryptoJS.enc.Base64);
  }

  /* RSA Verschlüsselung mit forge,  */
  static asyncCrypt(msg, pub_key) {
    var pubKey = forge.pki.publicKeyFromPem(pub_key);

    //RSA-OAEP hat nur begrenzte Zeichen,
    const emaxLength = 50;

    if (msg.length >= emaxLength) {
      let retA = [];
      for (let i = 0; i < msg.length; i += emaxLength) {
        retA.push(msg.slice(i, i + emaxLength));
      }
      Object.keys(retA).forEach((key) => {
        retA[key] = pubKey.encrypt(retA[key], "RSA-OAEP", {
          md: forge.md.sha256.create(),
          mgf1: {
            md: forge.md.sha256.create(),
          },
        });
        retA[key] = forge.util.encode64(retA[key]);
      });
      return retA;
    } else {
      let encmsg = pubKey.encrypt(msg, "RSA-OAEP", {
        md: forge.md.sha256.create(),
        mgf1: {
          md: forge.md.sha256.create(),
        },
      });
      return forge.util.encode64(encmsg);
    }
  }

  /* RSA ENT-Verschlüsselung mit forge,  */
  static asyncDecrypt(msg, priv_key) {
    var privKey = forge.pki.privateKeyFromPem(priv_key);

    //RSA-OAEP hat nur begrenzte Zeichen,
    const emaxLength = 50;

    if (Array.isArray(msg)) {
      Object.keys(msg).forEach((key) => {
        msg[key] = forge.util.decode64(msg[key]);
        msg[key] = privKey.decrypt(msg[key], "RSA-OAEP", {
          md: forge.md.sha256.create(),
          mgf1: {
            md: forge.md.sha256.create(),
          },
        });
      });
      return msg.join("");
    } else {
      msg = forge.util.decode64(msg);
      msg = privKey.decrypt(msg, "RSA-OAEP", {
        md: forge.md.sha256.create(),
        mgf1: {
          md: forge.md.sha256.create(),
        },
      });
      return msg;
    }
  }

  /* Symmetric decryption with AES */
  static symDecrypt(messageB64, key, ivB64) {
    var ciphertext = CryptoJS.enc.Base64.parse(messageB64);
    var iv = CryptoJS.enc.Base64.parse(ivB64);
    var params = { ciphertext: ciphertext, salt: "" };
    var clearText = CryptoJS.AES.decrypt(params, key, { iv: iv });
    return clearText.toString(CryptoJS.enc.Utf8);
  }

  /* creation of a random string with standard chars */
  static randomString(length) {
    var chars =
      "ABCDEFGHJKLMNOPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz0123456789!@#$%^&*()_+~|}{[]:;?><,./-=";
    var password = "";
    for (var i = 0; i < length; i++) {
      password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return password;
  }

  static generateRandomWord() {
    const alphabet = "abcdefghijklmnopqrstuvwxyz12234567890";
    const length = Math.floor(Math.random() * 10) + 1;
    let word = "";
    for (let i = 0; i < length; i++) {
      const index = Math.floor(Math.random() * alphabet.length);
      word += alphabet[index];
    }
    return word;
  }

  /* creation of a UUID */
  static getUUID() {
    let d = new Date().getTime();
    let uuid = "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(
      /[xy]/g,
      function (c) {
        let r = (d + Math.random() * 16) % 16 | 0;
        d = Math.floor(d / 16);
        return (c == "x" ? r : (r & 0x3) | 0x8).toString(16);
      }
    );
    return uuid;
  }
}
