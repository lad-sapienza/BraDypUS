/*jshint esversion: 6 */
const gulp = require('gulp');
const terser = require('gulp-terser');
const concat = require('gulp-concat');
const less = require('gulp-less');
const cleanCSS = require('gulp-clean-css');
const rename = require("gulp-rename");

const vendors = {
    "jquery": [
        "jquery/dist/jquery.min.js",
        "jquery/dist/jquery.min.map"
    ],
    "bootstrap": [
        "bootstrap/dist/js/bootstrap.min.js",
        "bootstrap/dist/css/bootstrap.min.css",
        "bootstrap/dist/css/bootstrap.min.css.map",
        "bootstrap/dist/fonts/*"
    ],
    "izitoast": [
        "izitoast/dist/js/iziToast.min.js",
        "izitoast/dist/css/iziToast.min.css"
    ],
    "datatables.net": [
        "datatables.net/js/jquery.dataTables.min.js"
    ],
    "datatables.net-bs": [
        "datatables.net-bs/js/dataTables.bootstrap.min.js",
        "datatables.net-bs/css/dataTables.bootstrap.min.css"
    ],
    "select2": [
        "select2/dist/js/select2.full.min.js",
        "select2/dist/js/i18n/*",
        "select2/dist/css/select2.min.css"
    ],
    "sortablejs": [
        "sortablejs/Sortable.min.js"
    ],
    "fancybox": [
        "@fancyapps/fancybox/dist/jquery.fancybox.min.js",
        "@fancyapps/fancybox/dist/jquery.fancybox.min.css"
    ],
    "font-awesome": [
        "font-awesome/css/font-awesome.min.css",
        "font-awesome/css/font-awesome.css.map",
        "font-awesome/fonts/*"
    ]
};

const bdusJs = [
    "php2js.js",
    "jquery.keyboard.js",
    "utils.js",
    "jquery.fineuploader-3.4.0.js",
    "core.js",
    "api.js",
    "layout.js",
    "formControls.js",
    "enhanceForm.js",
    "jquery.checklabel.js",
    "jquery.printElement.js",
    "jquery.jqplot.js",
    "jqplot.barRenderer.min.js",
    "jqplot.categoryAxisRenderer.min.js",
    "jqplot.pointLabels.js",
    "export-jqplot-to-png.js",
    "hashActions.js"
];

gulp.task('moveVendors', done => {
    Object.entries(vendors).forEach(([key, vals]) => {
        vals.forEach( (v) => {
          gulp.src(`node_modules/${v}`, {base: `./node_modules/${key}/`})
            .pipe(gulp.dest(`./assets/${key}/`));
        });
      });
    done();
});

gulp.task('compile2Css', () => {
    return gulp.src('./css-less/main.less')
        .pipe(less().on('error', function(err){ console.log(err.message); this.emit('end'); }))
        .pipe(cleanCSS())
        .pipe(rename('bdus.min.css'))
        .pipe(gulp.dest('./assets/bdus/'));
});

gulp.task('minifyBdusJs', () => {
    return gulp.src(bdusJs.map(e => `js-sources/${e}`))
        .pipe(terser())
        .pipe(concat(`bdus.min.js`))
        .pipe(gulp.dest('./assets/bdus/'))
});

gulp.task('minifyMods', () => {
    return gulp.src(['./modules/**/*.js', '!./modules/**/*.min.js'])
        .pipe(terser())
        .pipe(rename({ suffix: '.min' }))
        .pipe(gulp.dest( file => {
            return file.base;
        }))
});

gulp.task('build', gulp.series('moveVendors', 'compile2Css', 'minifyBdusJs', 'minifyMods'));

gulp.task('default', () => {
    gulp.watch(['./css-less/**/*.less'], gulp.series('compile2Css'));
    gulp.watch(['./js-sources/**/*.js'], gulp.series('minifyBdusJs'));
    gulp.watch(['./modules/**/*.js', '!./modules/**/*.min.js'], gulp.series('minifyMods'));
});