hljs.initHighlightingOnLoad();
hljs.initLineNumbersOnLoad();


$(document).ready(function(){

    $('#search-arg').on('search', function() {
        doSearch($(this).val());
    });
    $('#search-arg').on('keyup', function(){
        doSearch($(this).val());
    });

    function doSearch(filter) {
        if (filter && filter !== '') {
            $.each($('.searcheable li'), function(i, el){
            if ($(el).text().toLowerCase().indexOf(filter.toLowerCase()) > -1){
                $(el).removeClass('d-none');
            } else {
                $(el).addClass('d-none');
            }
            });
        } else {
            $('.searcheable li').removeClass('d-none');
        }
    }

    $('#search-dic').on('search', function() {
        doSearchDic($(this).val());
    });
    $('#search-dic').on('keyup', function(){
        doSearchDic($(this).val());
    });

    function doSearchDic(filter) {
        $('#content h3').each( function(){
            var h3Text = $(this).text().toLowerCase();
            var pEls = $(this).nextUntil('h3');
            if ( !filter ||  h3Text.indexOf(filter.toLowerCase()) > -1 ){
                $(this).removeClass('d-none');
                pEls.removeClass('d-none');
            } else if( h3Text.indexOf(filter.toLowerCase()) < 0 ) {
                $(this).addClass('d-none');
                pEls.addClass('d-none');
            }
        });
    }
});
