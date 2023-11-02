/**
 * Facebook Script
 *
 * @filesource js/facebook.js
 * @link https://www.kotchasan.com/
 * @copyright 2018 Goragod.com
 * @license https://www.kotchasan.com/license/
 */
function initFacebookButton(button) {
  callClick(button, function() {
    FB.login(
      function(response) {
        if (response.authResponse) {
          var accessToken = response.authResponse.accessToken;
          var uid = response.authResponse.userID;
          FB.api(
            "/" + uid,
            {
              access_token: accessToken,
              fields: "id,first_name,last_name,email,picture"
            },
            function(response) {
              if (!response.error) {
                var q = new Array();
                if ($E("token")) {
                  q.push("token=" + encodeURIComponent($E("token").value));
                }
                for (var prop in response) {
                  if (prop == 'picture') {
                    q.push('image=' + encodeURIComponent(response[prop]['data']['url']));
                  } else {
                    q.push(prop + '=' + encodeURIComponent(response[prop]));
                  }
                }
                send(WEB_URL + "index.php/" + ($E("facebook_action") ? $E("facebook_action").value : "index/model/fblogin/chklogin"), q.join("&"), fbLoginSubmit);
              }
            }
          );
        }
      }, {scope: "public_profile"}
    );
  });
}

function initFacebook(appId, lng) {
  window.fbAsyncInit = function() {
    FB.init({
      appId: appId,
      cookie: true,
      status: true,
      xfbml: true,
      version: "v17.0"
    });
  };
  loadJavascript("facebook-jssdk", "//connect.facebook.net/" + (lng == "th" ? "th_TH" : "en_US") + "/sdk.js");
}

function fbLoginSubmit(xhr) {
  var ds = xhr.responseText.toJSON();
  if (ds) {
    if (ds.alert) {
      alert(ds.alert);
    }
    if (ds.isMember == 1) {
      if ($E("login_action")) {
        window.location = $E("login_action").value;
      } else {
        window.location = replaceURL({}, {module: 'welcome'}).replace("action=logout", "action=login");
      }
    }
  } else if (xhr.responseText != "") {
    console.log(xhr.responseText);
  }
}
