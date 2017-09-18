/**
 * @license Copyright (c) 2003-2016, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

CKEDITOR.editorConfig = function (config) {
	/*  set class และ id สำหรับ body ของ ckeditor */
	config.bodyClass = 'cke-body content';

	config.language = 'th';
	config.defaultLanguage = 'th';

	/* set css สำหรับส่วน editor ของ ckeditor ให้เหมือนกับเว็บหลัก */
	config.contentsCss = WEB_URL + 'skin/gcss.css';

	/* ใช้ br แทน p เมื่อกด Enter */
	config.enterMode = CKEDITOR.ENTER_BR;
	config.shiftEnterMode = CKEDITOR.ENTER_P;

	/* Prevent filler nodes in all empty blocks */
	config.fillEmptyBlocks = false;

	/* เปลี่ยน em เป็น i */
	config.coreStyles_italic = {
		element: 'i'
	};

	/*  Special Character */
	config.specialChars = ['&quot;', '&#92;', '&amp;', '&lt;', '&gt;', '&lsquo;', '&rsquo;', '&ldquo;', '&rdquo;', '&prime;', '&Prime;', '&ndash;', '&mdash;',
		'&iexcl;', '&cent;', '&euro;', '&pound;', '&curren;', '&yen;', '&brvbar;', '&sect;', '&uml;', '&trade;', '&copy;', '&reg;', '&ordf;',
		'&laquo;', '&raquo;', '&not;', '&macr;', '&deg;', '&acute;', '&micro;', '&para;', '&#8494;', '&#8486;', '&middot;', '&cedil;',
		'&ordm;', '&sup1;', '&sup2;', '&sup3;', '&frac14;', '&frac12;', '&frac34;', '&#8319;', '&#8531;', '&#8532;', '&#8533;', '&#8534;',
		'&#8535;', '&#8536;', '&#8537;', '&#8538;', '&#8539;', '&#8540;', '&#8541;', '&#8542;', '&iquest;', '&Agrave;', '&Aacute;', '&Acirc;',
		'&Atilde;', '&Auml;', '&Aring;', '&AElig;', '&Ccedil;', '&Egrave;', '&Eacute;', '&Ecirc;', '&Euml;', '&Igrave;', '&Iacute;', '&Icirc;',
		'&Iuml;', '&ETH;', '&Ntilde;', '&Ograve;', '&Oacute;', '&Ocirc;', '&Otilde;', '&Ouml;', '&times;', '&Oslash;', '&Ugrave;',
		'&Uacute;', '&Ucirc;', '&Uuml;', '&Yacute;', '&THORN;', '&szlig;', '&agrave;', '&aacute;', '&acirc;', '&atilde;', '&auml;',
		'&aring;', '&aelig;', '&ccedil;', '&egrave;', '&eacute;', '&ecirc;', '&euml;', '&igrave;', '&iacute;', '&icirc;', '&iuml;',
		'&eth;', '&ntilde;', '&ograve;', '&oacute;', '&ocirc;', '&otilde;', '&ouml;', '&divide;', '&oslash;', '&ugrave;', '&uacute;',
		'&ucirc;', '&uuml;', '&yacute;', '&thorn;', '&yuml;', '&OElig;', '&oelig;', '&#372;', '&#374', '&#373', '&#375;', '&sbquo;',
		'&#8219;', '&bdquo;', '&hellip;', '&bull;', '&rArr;', '&hArr;', '&asymp;', '&#10003;', '&#x2718;', '&#9650;', '&#9660;',
		'&#9658;', '&#9668;', '&larr;', '&rarr;', '&uarr;', ' &darr;', '&harr;', '&#8597;', '&#8252;', '&ne;', '&#8710;', '&int;', '&equiv;',
		'&le;', '&ge;', '&infin;', '&spades;', '&clubs;', '&hearts;', '&diams;', '&#9834;', '&#9835;', '&#152;', '&#8470;', '&#9733;',
		'&#9786;', '&#9787;', '&#9788;', '&#9792;', '&#9794;', '&#2947;', '&#x007B;', '&#x007D;'
	];

	/* Turning off the ACF */
	config.allowedContent = true;

	/* ไม่ต้องตรวจสอบ tag เหล่านี้ */
	config.protectedSource.push(/<script[^>]*><\/script>/g);
	config.protectedSource.push(/<style[^>]*><\/style>/g);
	config.protectedSource.push(/<ins[^>]*><\/ins>/g);
	config.protectedSource.push(/<span[^>]*><\/span>/g);
	config.protectedSource.push(/<a[^>]*><\/a>/g);
	config.protectedSource.push(/<div[^>]*><\/div>/g);
	config.protectedSource.push(/<ul[^>]*><\/ul>/g);

	/* font size */
	config.fontSize_sizes = '1em;1.2em;1.4em;1.6em;1.8em;2em;2.5em;3em;3.5em;4em';

	/* ปิดการปรับขนาด Editor */
	config.resize_enabled = false;

	/* tab size */
	config.tabSpaces = 4;

	/* พื้นหลังสีดำ */
	config.dialog_backgroundCoverColor = '#000000';

	config.pasteFromWordRemoveStyles = true;

	/* disabled auto remove empty tag */
	CKEDITOR.dtd.$removeEmpty['span'] = false;
	CKEDITOR.dtd.$removeEmpty['a'] = false;
	CKEDITOR.dtd.$removeEmpty['div'] = false;
	CKEDITOR.dtd.$removeEmpty['li'] = false;
	CKEDITOR.dtd.$removeEmpty['ins'] = false;
	CKEDITOR.dtd.$removeEmpty['script'] = false;
	CKEDITOR.dtd.$removeEmpty['style'] = false;
	/* Smile Icons */
	config.smiley_images = ['regular_smile.gif', 'sad_smile.gif', 'wink_smile.gif', 'teeth_smile.gif', 'confused_smile.gif', 'tounge_smile.gif', 'embaressed_smile.gif', 'omg_smile.gif', 'whatchutalkingabout_smile.gif', 'angry_smile.gif', 'angel_smile.gif', 'shades_smile.gif', 'devil_smile.gif', 'cry_smile.gif', 'lightbulb.gif', 'thumbs_down.gif', 'thumbs_up.gif', 'heart.gif', 'broken_heart.gif', 'kiss.gif', 'envelope.gif', '21.gif', '22.gif', '23.gif', '24.gif', '25.gif', '26.gif', '27.gif', '28.gif', '30.gif', '32.gif', '33.gif', '34.gif', '35.gif', '36.gif', '37.gif', '38.gif', '39.gif', '42.gif', '43.gif', '44.gif', '45.gif', '46.gif', '47.gif', '48.gif'];
	config.smiley_descriptions = ['smiley', 'sad', 'wink', 'laugh', 'frown', 'cheeky', 'blush', 'surprise', 'indecision', 'angry', 'angle', 'cool', 'devil', 'crying', 'enlightened', 'no', 'yes', 'heart', 'broken heart', 'kiss', 'mail', ':21:', ':22:', ':23:', ':24:', ':25:', ':26:', ':27:', ':28:', ':30:', ':32:', ':33:', ':34:', ':35:', ':36:', ':37:', ':38:', ':39:', ':42:', ':43:', ':44:', ':45:', ':46:', ':47:', ':48:'];

	/* format combo HTML5 */
	config.format_tags = 'p;div;section;article;header;footer;aside;h1;h2;h3;h4;h5;h6;pre;address';
	config.format_section = {element: 'section', name: 'Section'};
	config.format_article = {element: 'article', name: 'Article'};
	config.format_header = {element: 'header', name: 'Header'};
	config.format_footer = {element: 'footer', name: 'Footer'};
	config.format_aside = {element: 'aside', name: 'Aside'};

	/* toolbar */
	config.toolbar_Blog = [
		['Undo', 'Redo'],
		['RemoveFormat', 'Bold', 'Italic', 'Underline', 'Strike', '-', 'Subscript', 'Superscript'],
		['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock'],
		['Link', 'Unlink', 'Image', 'Flash'],
		['Table', 'Smiley', 'SpecialChar'],
		['NumberedList', 'BulletedList'],
		'/',
		['TextColor', 'BGColor'],
		['FontSize', 'Styles', 'Format']
	];
	config.toolbar_Document = [
		['Source', 'Maximize'],
		['Undo', 'Redo', '-', 'Find', 'Replace'],
		['RemoveFormat', 'Bold', 'Italic', 'Underline', 'Strike', '-', 'Subscript', 'Superscript'],
		['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock'],
		['Link', 'Unlink', 'Image', 'Flash'],
		['NumberedList', 'BulletedList'],
		'/',
		['Styles', 'Format', 'Font', 'FontSize'],
		['TextColor', 'BGColor'],
		['ShowBlocks', 'Templates'],
		['Table', 'Smiley', 'SpecialChar', 'Iframe']
	];
	config.toolbar_Comment = [
		['RemoveFormat', 'Bold', 'Italic', 'Underline', 'Strike'],
		['NumberedList', 'BulletedList'],
		['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock'],
		['Link', 'Unlink', 'Image', 'Flash'],
		['Smiley', 'SpecialChar'],
		['TextColor', 'BGColor']
	];
	config.toolbar_Email = [
		['Undo', 'Redo'],
		['RemoveFormat', 'Bold', 'Italic', 'Underline', 'Strike'],
		['NumberedList', 'BulletedList'],
		['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock'],
		['Link', 'Unlink', 'Image'],
		['Table', 'Smiley', 'SpecialChar'],
		'/',
		['TextColor', 'BGColor'],
		['FontSize', 'Styles', 'Format']
	];
	config.toolbar_AdminEmail = [
		['Source'],
		['Undo', 'Redo'],
		['RemoveFormat', 'Bold', 'Italic', 'Underline', 'Strike'],
		['NumberedList', 'BulletedList'],
		['JustifyLeft', 'JustifyCenter', 'JustifyRight', 'JustifyBlock'],
		['Link', 'Unlink', 'Image'],
		['Table', 'Smiley', 'SpecialChar'],
		'/',
		['TextColor', 'BGColor'],
		['FontSize', 'Styles', 'Format']
	];
};
var ck_unit = /([0-9]+)(%|em|px)?/;
CKEDITOR.on('instanceReady', function (ev) {
	/* not used /> (xHTML) */
	ev.editor.dataProcessor.writer.selfClosingEnd = '>';
	ev.editor.dataProcessor.htmlFilter.addRules({
		elements: {
			$: function (element) {
				// remove width,height for responsive image
				if (element.name == 'img') {
					var style = element.attributes.style;
					if (style) {
						element.attributes.style = element.attributes.style.replace(/(?:^|\s)width\s*:\s*(\d+)(%|em|px)(;?)/i, '');
						element.attributes.style = element.attributes.style.replace(/(?:^|\s)height\s*:\s*(\d+)(%|em|px)(;?)/i, '');
					}
					if (!element.attributes.style) {
						delete element.attributes.style;
					}
					delete element.attributes.width;
					delete element.attributes.height;
				} else if (element.name == 'a') {
					// add rel=nofollow
					element.attributes.rel = 'nofollow';
				} else if (element.name == 'iframe') {
					var style = element.attributes.style || '';
					if (element.attributes.width) {
						style = style.replace(/(?:^|\s)width\s*:\s*(\d+)(%|em|px);?/i, '');
						var s = ck_unit.exec(element.attributes.width);
						style = 'width:' + s[1] + (s[2] ? s[2] : 'px') + ';' + style;
					}
					if (element.attributes.height) {
						style = style.replace(/(?:^|\s)height\s*:\s*(\d+)(%|em|px);?/i, '');
						var s = ck_unit.exec(element.attributes.height);
						style = 'height:' + s[1] + (s[2] ? s[2] : 'px') + ';' + style;
					}
					if (style != '') {
						element.attributes.style = style;
					}
					delete element.attributes.width;
					delete element.attributes.height;
					delete element.attributes.border;
					delete element.attributes.scrolling;
					delete element.attributes.allowtransparency;
					delete element.attributes.frameborder;
				}
				return element;
			}
		}
	});
});