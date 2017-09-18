/**
 * Javascript Libraly for GCMS (front-end)
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
          send(WEB_URL + 'xhr.php/index/model/fblogin/chklogin', q.join('&'), function (xhr) {
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
function loaddoc(url) {
  if (loader && url != WEB_URL) {
    loader.location(url);
  } else {
    window.location = url;
  }
}
var initWeb = function () {
  loader = new GLoader(WEB_URL + 'loader.php/index/controller/loader/index', function (xhr) {
    var scroll_to = 'scroll-to';
    var content = $G('content');
    var datas = xhr.responseText.toJSON();
    if (datas) {
      for (var prop in datas) {
        var value = datas[prop];
        if (prop == 'detail') {
          content.setHTML(value);
          loader.init(content);
          value.evalScript();
        } else if (prop == 'topic') {
          document.title = value.unentityify();
        } else if (prop == 'menu') {
          selectMenu(value);
        } else if (prop == 'to') {
          scroll_to = value;
        } else if ($E(prop)) {
          $E(prop).innerHTML = value;
        }
      }
      if ($E(scroll_to)) {
        window.scrollTo(0, $G(scroll_to).getTop() - 10);
      }
    } else {
      content.setHTML(xhr.responseText);
    }
  });
  loader.initLoading('wait', false);
  loader.init(document);
};