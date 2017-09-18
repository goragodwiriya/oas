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