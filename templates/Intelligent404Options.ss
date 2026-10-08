<h4><%t Intelligent404.OptionsHeader "Were you looking for one of the following?" %></h4>
<% if $Pages %>
    <%-- "404options" starts with a digit, so ".404options" is not a valid CSS selector (#4): style
         "intelligent404-options". The old class stays so existing themes keep matching. --%>
    <ul class="404options intelligent404-options">
    	<% loop $Pages %>
    		<li>
    			<a href="$Link">
    				<strong>$MenuTitle</strong> -
    				<i>$Link</i>
    			</a>
    		</li>
    	<% end_loop %>
    </ul>
<% end_if %>
