/**
 * Copyright (c) 2003-2017, CKSource - Frederico Knabben. All rights reserved.
 * For licensing, see LICENSE.md or http://ckeditor.com/license
 */

// This file contains style definitions that can be used by CKEditor plugins.
//
// The most common use for it is the "stylescombo" plugin which shows the Styles drop-down
// list containing all styles in the editor toolbar. Other plugins, like
// the "div" plugin, use a subset of the styles for their features.
//
// If you do not have plugins that depend on this file in your editor build, you can simply
// ignore it. Otherwise it is strongly recommended to customize this file to match your
// website requirements and design properly.
//
// For more information refer to: http://docs.ckeditor.com/#!/guide/dev_styles-section-style-rules

CKEDITOR.stylesSet.add( 'default', [
	/* Block Styles */
	{ name : 'คำพูด (Quote)'		, element : 'blockquote'},
	{ name : 'โค้ด (Code)'		, element : 'code' },
	{ name : 'ข้อความ (Message)'		, element : 'div', attributes : { 'class' : 'message' } },
	{ name : 'คำเตือน (Warning)'		, element : 'div', attributes : { 'class' : 'warning' } },
	{ name : 'Image center'		, element : 'figure', attributes : { 'class' : 'center' } },
	/* Inline Styles */
	{ name : 'Span tag'		, element : 'span' },
	{ name : 'สำคัญ (Important)'		, element : 'em' },
	{ name : 'เน้นข้อความ (Bold)'		, element : 'strong' },
	{ name : 'หมายเหตุ (Comment)'		, element : 'span', attributes : { 'class' : 'comment' } }
]);

CKEDITOR.stylesSet.add( 'print', [
	{ name : 'ช่องกรอกข้อความ'		, element : 'span', attributes : { 'class' : 'line' } },
	{ name : 'ขึ้นหน้าใหม่เมื่อพิมพ์'		, element : 'p', attributes : { 'class' : 'splitpage' } }
]);