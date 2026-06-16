(function (wp) {
  if (!wp || !wp.plugins || !wp.editPost || !wp.element || !wp.data || !wp.components) {
    return;
  }

  const { registerPlugin } = wp.plugins;
  const { PluginDocumentSettingPanel } = wp.editPost;
  const { createElement: el } = wp.element;
  const { TextareaControl } = wp.components;
  const { useDispatch, useSelect } = wp.data;
  const { __ } = wp.i18n;

  function SummaryPanel() {
    const { postType, excerpt } = useSelect((select) => {
      const editor = select('core/editor');
      return {
        postType: editor.getCurrentPostType(),
        excerpt: editor.getEditedPostAttribute('excerpt') || '',
      };
    }, []);
    const { editPost } = useDispatch('core/editor');

    if (postType !== 'post') {
      return null;
    }

    const value = typeof excerpt === 'string' ? excerpt : '';
    const plainLength = value.replace(/<[^>]*>/g, '').trim().length;

    return el(
      PluginDocumentSettingPanel,
      {
        name: 'pkb-post-summary',
        title: __('요약', 'pkb-core'),
        className: 'pkb-post-summary-panel',
      },
      el(TextareaControl, {
        label: __('SEO 및 미리보기 요약', 'pkb-core'),
        help: __('입력하면 검색엔진 설명과 홈페이지/검색 결과 미리보기에 함께 사용됩니다. 비워두면 본문에서 자동 생성됩니다.', 'pkb-core'),
        value,
        rows: 5,
        onChange: (nextValue) => editPost({ excerpt: nextValue }),
      }),
      el(
        'p',
        { className: 'pkb-post-summary-count' },
        plainLength
          ? __('권장 길이: 80-150자. 현재 ', 'pkb-core') + plainLength + __('자', 'pkb-core')
          : __('요약을 입력하지 않으면 기존 자동 설명을 사용합니다.', 'pkb-core')
      )
    );
  }

  registerPlugin('pkb-post-summary', {
    render: SummaryPanel,
  });
})(window.wp);
