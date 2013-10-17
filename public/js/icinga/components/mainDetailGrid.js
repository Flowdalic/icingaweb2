/*global Icinga:false, document: false, define:false require:false base_url:false console:false */
// {{{ICINGA_LICENSE_HEADER}}}
/**
 * This file is part of Icinga 2 Web.
 *
 * Icinga 2 Web - Head for multiple monitoring backends.
 * Copyright (C) 2013 Icinga Development Team
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @copyright  2013 Icinga Development Team <info@icinga.org>
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GPL, version 2
 * @author     Icinga Development Team <info@icinga.org>
 */
// {{{ICINGA_LICENSE_HEADER}}}
define(
    [
        'components/app/container',
        'jquery',
        'logging',
        'icinga/selection/selectable',
        'icinga/selection/multiSelection',
        'URIjs/URI',
        'URIjs/URITemplate'
    ],
function(Container, $, logger, Selectable, TableMultiSelection, URI) {
    "use strict";

    /**
     * Master/Detail grid component handling history, link behaviour, selection (@TODO 3788) and updates of
     * grids
     *
     * @param {HTMLElement} The outer element to apply the behaviour on
     */
    return function(gridDomNode) {

        /**
         * Reference to the outer container of this component
         *
         * @type {*|HTMLElement}
         */
        gridDomNode = $(gridDomNode);

        /**
         * A container component to use for updating URLs and content
         *
         * @type {Container}
         */
        this.container = null;

        /**
         * The node wrapping the table and pagination
         *
         * @type {jQuery}
         */
        var contentNode;

        /**
         * jQuery matcher result of the form components wrapping the controls
         *
         * @type {jQuery}
         */
        var controlForms;

        /**
         * Handles multi-selection
         *
         * @type {TableMultiSelection}
         */
        var selection;

        /**
         * Detect and select control forms for this table and return them
         *
         * Form controls are either all forms underneath the of the component, but not underneath the table
         * or in a dom node explicitly tagged with the 'data-icinga-actiongrid-controls' attribute
         *
         * @param   {jQuery|null} domContext            The context to use as the root node for matching, if null
         *                                              the component node given in the constructor is used
         *
         * @returns {jQuery}                            A selector result with all forms modifying this grid
         */
        var determineControlForms = function(domContext) {
            domContext = domContext || gridDomNode;
            var controls = $('[data-icinga-grid-controls]', domContext);
            if (controls.length > 0) {
                return $('form', controls);
            } else {
                return $('form', domContext).filter(function () {
                    return $(this).parentsUntil(domContext, 'table').length === 0;
                });
            }
        };

        /**
         * Detect and select the dom of all tables displaying content for this mainDetailGrid component
         *
         * The table can either explicitly tagged with the 'data-icinga-grid-content' attribute, if not every table
         * underneath the components root dom will be used
         *
         * @param   {jQuery|null} domContext            The context to use as the root node for matching, if null
         *                                              the component node given in the constructor is used
         *
         * @returns {jQuery}                            A selector result with all tables displaying information in the
         *                                              grid
         */
        var determineContentTable = function(domContext) {
            domContext = domContext || gridDomNode;
            var maindetail = $('[data-icinga-grid-content]', domContext);
            if (maindetail.length > 0) {
                return maindetail;
            } else {
                return $('table', domContext);
            }
        };

        /**
         * Register the row links of tables using the first link found in the table (no matter if visible or not)
         *
         * Row level links can only be realized via JavaScript, so every row should provide additional links for
         * Users that don't have javascript enabled
         *
         * @param {jQuery|null} domContext          The rootnode to use for selecting rows or null to use contentNode
         */
        this.registerTableLinks = function(domContext) {
            domContext = domContext || contentNode;

            $('tbody tr', domContext).on('click', function(ev) {
                var targetEl = ev.target || ev.toElement || ev.relatedTarget,
                    a = $(targetEl).closest('a');

                var nodeNames = [];
                nodeNames.push($(targetEl).prop('nodeName').toLowerCase());
                nodeNames.push($(targetEl).parent().prop('nodeName').toLowerCase());

                if (a.length) {
                    // test if the URL is on the current server, if not open it directly
                    if (Container.isExternalLink(a.attr('href'))) {
                        return true;
                    }
                } else if ($.inArray('input', nodeNames) > -1 || $.inArray('button', nodeNames) > -1) {
                    var type = $(targetEl).attr('type') || $(targetEl).parent().attr('type');
                    if (type === 'submit') {
                        return true;
                    }
                }

                var selectable = new Selectable(this);
                if (ev.ctrlKey || ev.metaKey) {
                    selection.toggle(selectable);
                } else if (ev.shiftKey) {
                    // select range ?
                    selection.add(selectable);
                } else {
                    selection.clear();
                    selection.add(selectable);
                }

                // TODO: Detail target
                var url = URI($('a', this).attr('href'));
                var segments = url.segment();
                if (selection.size() === 0) {
                    // don't open anything
                    url.search('?');
                } else if (selection.size() > 1 && segments.length > 3) {
                    // open detail view for multiple objects
                    segments[2] = 'multi';
                    url.pathname(segments.join('/'));
                    url.search('?');
                    url.setSearch(selection.toQuery());
                }
                Container.getDetailContainer().replaceDomFromUrl(url);
                return false;
            });
        };

        /**
         * Register submit handler for the form controls (sorting, filtering, etc). Reloading happens in the
         * current container
         */
        this.registerControls = function() {
            controlForms.on('submit', function(evt) {
                var container = (new Container(this));
                var form = $(this);
                var url = URI(container.getContainerHref());
                url.search(URI.parseQuery(form.serialize()));
                container.replaceDomFromUrl(url.href());

                evt.preventDefault();
                evt.stopPropagation();
                return false;

            });
            $('.pagination li a', contentNode.parent()).on('click', function(ev) {

                var container = (new Container(this));
                logger.debug("Pagination clicked in " + container.containerType);
                // Detail will be removed when main pagination changes
                if (container.containerType === 'icingamain') {
                    Icinga.replaceBodyFromUrl(URI($(this).attr('href')).removeQuery('detail'));
                } else {
                    container.replaceDomFromUrl($(this).attr('href'));
                }

                ev.preventDefault();
                ev.stopPropagation();
                return false;
            });
        };

        var getSelectedRows = function() {
            return $('a[href="' + Container.getDetailContainer().getContainerHref() + '"]', determineContentTable()).
                parentsUntil('table', 'tr');
        };

        /**
         * Synchronize the current selection with the url displayed in the detail box
         */
        this.syncSelectionWithDetail = function() {
            $('tr', contentNode).removeClass('active');
            getSelectedRows().addClass('active');
        };

        /**
         * Register listener for history changes in the detail box
         */
        this.registerHistoryChanges = function() {
            Container.getDetailContainer().registerOnUpdate(this.syncSelectionWithDetail.bind(this));
        };

        /**
         * Create this component, setup listeners and behaviour
         */
        this.construct = function(target) {
            this.container = new Container(target);
            this.container.removeDefaultLoadIndicator();
            controlForms = determineControlForms();
            contentNode = determineContentTable();
            selection = new TableMultiSelection(
                contentNode,
                Container.getDetailContainer().getContainerHref()
            );
            this.registerControls();
            this.registerTableLinks();
            this.registerHistoryChanges();
        };

        this.construct(gridDomNode);
    };
});
