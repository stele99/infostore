class m_entry {
  constructor(uid = "") {
    if (uid != "") {
      this.init(uid);
    } else {
      this.uid = "";
    }
  }

  init(uid) {
    const entry = this.entries.filter((item) => item.uid === uid);
    let html = "";
    $("#shareContainer").hide();
    $("#editContainer").show();

    if (entry.length > 0) {
      $("#e_uid").val(entry[0].uid);
      $("#e_title").val(se_crypt.symDecrypt(entry[0].title, key, entry[0].iv));
      html = se_crypt.symDecrypt(entry[0].data, key, entry[0].iv);
      $("#f_changed").html(entry[0].ts_changed);
      $("#f_created").html(entry[0].ts_created);
    }
    if (uid == "") {
      $("#e_uid").val("");
      $("#e_title").val("");
      $("#f_changed").html("");
      $("#f_created").html("");
    }
    $(".show-file-list").removeClass("show-file-list");
    quill.clipboard.dangerouslyPasteHTML(html);
  }

  mapData() {
    const dataObject = {};

    // Select all input, textarea, and div elements with the class 'mapdata'
    const elements = document.querySelectorAll(".mapdata");

    // Iterate over the selected elements
    elements.forEach((element) => {
      const key = element.getAttribute("id");
      const value = element.value || element.innerHTML;
      // Check if key is valid and add to the object
      if (key) {
        dataObject[key] = value.trim(); // Use trim() to clean whitespace
      }
    });
    return dataObject;
  }

  save() {
    this.data = this.mapData();
    if (this.data.e_uid == "") {
      this.data.e_uid = se_crypt.getUUID();
    }

    let userId = localStorage.getItem("userId");
    let c_title = se_crypt.symCrypt(this.data.e_title, key);
    let iv = c_title.iv;
    let c_data = se_crypt.symCrypt(this.data.e_data, key, iv);

    let adata = {
      f_uid: this.data.e_uid,
      f_userid: userId,
      f_title: c_title.msg,
      f_data: c_data.msg,
      f_iv: iv,
    };
    sendData(adata, "save", eC.save_2);
  }

  save_2(data) {
    eC.loadItems(data.uid);
  }

  delete() {
    this.data = this.mapData();
    let userId = localStorage.getItem("userId");

    let adata = {
      f_uid: this.data.e_uid,
      f_userid: userId,
    };

    sendData(adata, "delete", eC.delete_2);
  }

  delete_2() {
    alert("Entry has been deleted");
    eC.init("");
    eC.loadItems();
  }

  loadItems(uid = "") {
    let c = se_crypt;
    let userId = localStorage.getItem("userId");

    let adata = {
      f_userid: userId,
    };

    sendData(adata, "getlist", function (data) {
      eC.entries = data;
      $("#file-list").html("");
      const listItem = $("<li class='editfile' data-uid=''>").text(
        "➕ New Item"
      );
      $("#file-list").append(listItem);

      $.each(data, function (index, item) {
        // Create a new list item
        let title = se_crypt.symDecrypt(item.title, key, item.iv);
        const listItem = $("<li class='editfile'>").text(title);
        // Optionally, you can add a custom attribute or value if needed
        listItem.attr("data-uid", item.uid);
        // Append the list item to the #file-list
        $("#file-list").append(listItem);
      });
      eC.init(uid);

      $(".editfile").off("click");
      $(".editfile").on("click", function () {
        eC.init($(this).attr("data-uid"));
      });
    });
  }
}
