/**
 * Facebook Script
 *
 * @filesource js/gcms.js
 * @link http://www.kotchasan.com/
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 */
function fbLogin() {
  FB.login(function (response) {
    if (response.authResponse) {
      var accessToken = response.authResponse.accessToken;
      var uid = response.authResponse.userID;
      FB.api('/' + uid, {access_token: accessToken, fields: 'id,first_name,last_name,email,link'}, function (response) {
        if (!response.error) {
          var q = new Array();
          q.push('token=' + encodeURIComponent($E('token').value));
          for (var prop in response) {
            q.push(prop + '=' + encodeURIComponent(response[prop]));
          }
          send(WEB_URL + 'index.php/index/model/fblogin/chklogin', q.join('&'), function (xhr) {
            var ds = xhr.responseText.toJSON();
            if (ds) {
              if (ds.alert) {
                alert(ds.alert);
              }
              if (ds.isMember == 1) {
                window.location = window.location.href.replace('action=logout', 'action=login');
              }
            } else if (xhr.responseText != '') {
              alert(xhr.responseText);
            }
          });
        }
      });
    }
  }, {scope: 'email,public_profile'});
}
function initFacebook(appId, lng) {
  window.fbAsyncInit = function () {
    FB.init({
      appId: appId,
      cookie: true,
      status: true,
      xfbml: true,
      version: 'v2.8'
    });
  };
  (function (d, s, id) {
    var js, fjs = d.getElementsByTagName(s)[0];
    if (d.getElementById(id)) {
      return;
    }
    js = d.createElement(s);
    js.id = id;
    js.src = "//connect.facebook.net/" + (lng == 'th' ? 'th_TH' : 'en_US') + "/sdk.js";
    fjs.parentNode.insertBefore(js, fjs);
  }(document, 'script', 'facebook-jssdk'));
}
